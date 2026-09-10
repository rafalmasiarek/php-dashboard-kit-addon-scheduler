<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Lock;

use rafalmasiarek\DashboardKitScheduler\Clock;

/**
 * Single-file process lock using flock(LOCK_EX|LOCK_NB).
 *
 * Writes JSON metadata (pid + started_at) into the lock file for diagnostics.
 * Registers a shutdown handler and optional PCNTL signal handlers to release
 * the lock on normal exit, SIGTERM, SIGINT and SIGHUP.
 *
 * SIGKILL (kill -9) cannot be handled; the lock file may remain on disk in that case.
 * The advisory lock itself is released by the OS when the process dies.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class ProcessFileLock
{
    /**
     * @var string
     */
    private string $lockPath;

    /**
     * @var Clock|null
     */
    private ?Clock $clock;

    /**
     * @var resource|null
     */
    private $fh = null;

    /**
     * @var bool
     */
    private bool $locked = false;

    /**
     * @var bool
     */
    private bool $handlersInstalled = false;

    /**
     * @param string     $lockPath Absolute path to the lock file.
     * @param Clock|null $clock    Optional Clock for lock metadata timestamps.
     */
    public function __construct(string $lockPath, ?Clock $clock = null)
    {
        $this->lockPath = $lockPath;
        $this->clock    = $clock;
    }

    /**
     * Try to acquire the lock (non-blocking).
     *
     * @return bool True when the lock was acquired.
     */
    public function acquire(): bool
    {
        $dir = \dirname($this->lockPath);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        $fh = @\fopen($this->lockPath, 'c+');
        if ($fh === false) {
            return false;
        }

        if (!@\flock($fh, \LOCK_EX | \LOCK_NB)) {
            @\fclose($fh);
            return false;
        }

        $this->fh     = $fh;
        $this->locked = true;

        $this->writeMetaToLockFile();
        $this->installHandlers();

        return true;
    }

    /**
     * Release the lock.
     *
     * @return void
     */
    public function release(): void
    {
        if (!$this->locked) {
            return;
        }

        if (\is_resource($this->fh)) {
            @\ftruncate($this->fh, 0);
            @\fflush($this->fh);
            @\flock($this->fh, \LOCK_UN);
            @\fclose($this->fh);
        }

        $this->fh     = null;
        $this->locked = false;

        @\unlink($this->lockPath);
    }

    /**
     * Read lock metadata written at acquire time (best-effort).
     *
     * @return array<string,mixed>|null
     */
    public function readMeta(): ?array
    {
        $raw = @\file_get_contents($this->lockPath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = \json_decode($raw, true);
        return \is_array($data) ? $data : null;
    }

    /**
     * Write PID and start time into the lock file for diagnostics.
     *
     * @return void
     */
    private function writeMetaToLockFile(): void
    {
        if (!\is_resource($this->fh)) {
            return;
        }

        $startedAt = $this->clock !== null
            ? $this->clock->now()->format(\DATE_ATOM)
            : (new \DateTimeImmutable('now'))->format(\DATE_ATOM);

        $payload = ['pid' => \getmypid(), 'started_at' => $startedAt];
        $json    = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        @\ftruncate($this->fh, 0);
        @\rewind($this->fh);
        @\fwrite($this->fh, $json);
        @\fflush($this->fh);
    }

    /**
     * Register shutdown and optional PCNTL signal handlers.
     *
     * @return void
     */
    private function installHandlers(): void
    {
        if ($this->handlersInstalled) {
            return;
        }
        $this->handlersInstalled = true;

        \register_shutdown_function(function (): void {
            $this->release();
        });

        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            @\pcntl_async_signals(true);

            $handler = function (int $sig): void {
                $this->release();
                \exit(128 + $sig);
            };

            @\pcntl_signal(\SIGTERM, $handler);
            @\pcntl_signal(\SIGINT, $handler);
            @\pcntl_signal(\SIGHUP, $handler);
        }
    }
}
