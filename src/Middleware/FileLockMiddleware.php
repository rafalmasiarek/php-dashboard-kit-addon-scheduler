<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Middleware;

use rafalmasiarek\DashboardKitScheduler\Clock;
use rafalmasiarek\DashboardKitScheduler\Exception\TaskLockedException;
use rafalmasiarek\DashboardKitScheduler\Lock\ProcessFileLock;
use rafalmasiarek\DashboardKitScheduler\TaskMiddlewareInterface;

/**
 * Per-task file-lock middleware preventing concurrent execution.
 *
 * Lock file path: <locksDir>/<sanitized-task-name>.lock
 *
 * Guarantees lock release on normal exit and SIGTERM/SIGINT/SIGHUP
 * when the pcntl extension is available. SIGKILL cannot be handled.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class FileLockMiddleware implements TaskMiddlewareInterface
{
    /**
     * @var string
     */
    private string $locksDir;

    /**
     * @var Clock|null
     */
    private ?Clock $clock;

    /**
     * @param string     $locksDir Directory for lock files (absolute path).
     * @param Clock|null $clock    Optional Clock used for lock metadata timestamps.
     * @throws \RuntimeException When $locksDir is empty.
     */
    public function __construct(string $locksDir, ?Clock $clock = null)
    {
        $locksDir = \rtrim($locksDir, '/');
        if ($locksDir === '') {
            throw new \RuntimeException('FileLockMiddleware: locksDir must not be empty.');
        }

        $this->locksDir = $locksDir;
        $this->clock    = $clock;
    }

    /**
     * {@inheritdoc}
     *
     * @throws TaskLockedException When lock cannot be acquired.
     */
    public function handle(string $taskName, array $context, callable $next): mixed
    {
        $safe = $this->sanitizeTaskName($taskName);
        $path = $this->locksDir . '/' . $safe . '.lock';

        $lock = new ProcessFileLock($path, $this->clock);

        if (!$lock->acquire()) {
            $meta   = $lock->readMeta();
            $suffix = '';

            if (\is_array($meta)) {
                $pid     = $meta['pid'] ?? null;
                $started = $meta['started_at'] ?? null;

                $suffix = ' (locked';
                if ($pid !== null) {
                    $suffix .= ", pid={$pid}";
                }
                if ($started !== null) {
                    $suffix .= ", started_at={$started}";
                }
                $suffix .= ')';
            }

            throw new TaskLockedException("Task '{$taskName}' is locked{$suffix}.");
        }

        try {
            return $next();
        } finally {
            $lock->release();
        }
    }

    /**
     * Sanitize task name to a safe filename component.
     *
     * @param string $name
     * @return string
     */
    private function sanitizeTaskName(string $name): string
    {
        $name = \trim($name);
        if ($name === '') {
            return 'task';
        }

        $name = (string) \preg_replace('~[^a-zA-Z0-9._-]+~', '_', $name);

        return $name === '' ? 'task' : $name;
    }
}
