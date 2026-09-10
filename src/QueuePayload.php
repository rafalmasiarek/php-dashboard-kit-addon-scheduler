<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * Per-queue key-value storage persisted inside the queue state JSON file.
 *
 * In direct mode (no queue_state_path), it falls back to in-memory storage
 * and never throws. In queue mode, coordinates writes via a "<path>.lock" file
 * using the same convention as the queue runner.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class QueuePayload
{
    /**
     * When null the payload is in-memory only (no persistence).
     *
     * @var string|null
     */
    private ?string $queueStatePath;

    /**
     * @var array<string,mixed>
     */
    private array $memory = [];

    /**
     * @param string|null $queueStatePath Absolute path to the queue JSON file, or null for in-memory.
     */
    public function __construct(?string $queueStatePath)
    {
        $this->queueStatePath = $queueStatePath !== '' ? $queueStatePath : null;
    }

    /**
     * Create payload accessor from scheduler context array.
     *
     * Accepts common context shapes:
     * - flat:   ['queue_state_path' => '...']
     * - nested: ['context' => ['queue_state_path' => '...']]
     * - nested: ['data' => ['queue_state_path' => '...']]
     *
     * Returns in-memory instance when path is missing.
     *
     * @param array<string,mixed> $context
     * @return self
     */
    public static function fromContext(array $context): self
    {
        $path = '';

        if (isset($context['queue_state_path'])) {
            $path = (string) $context['queue_state_path'];
        }

        if ($path === '' && isset($context['context']) && \is_array($context['context'])) {
            $path = (string) ($context['context']['queue_state_path'] ?? '');
        }

        if ($path === '' && isset($context['data']) && \is_array($context['data'])) {
            $path = (string) ($context['data']['queue_state_path'] ?? '');
        }

        $path = \trim($path);
        return new self($path !== '' ? $path : null);
    }

    /**
     * Whether the payload is persisted to the queue file.
     *
     * @return bool
     */
    public function isPersistent(): bool
    {
        return $this->queueStatePath !== null;
    }

    /**
     * Get a value by key.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->queueStatePath === null) {
            return \array_key_exists($key, $this->memory) ? $this->memory[$key] : $default;
        }

        return $this->withLock(function () use ($key, $default) {
            $state   = $this->readStateUnlocked();
            $payload = $this->readPayload($state);

            return \array_key_exists($key, $payload) ? $payload[$key] : $default;
        });
    }

    /**
     * Store a value by key.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public function put(string $key, mixed $value): void
    {
        if ($this->queueStatePath === null) {
            $this->memory[$key] = $value;
            return;
        }

        $this->withLock(function () use ($key, $value): void {
            $state = $this->readStateUnlocked();

            if (!isset($state['payload']) || !\is_array($state['payload'])) {
                $state['payload'] = [];
            }

            $state['payload'][$key] = $value;
            $this->writeStateUnlocked($state);
        });
    }

    /**
     * Remove a value by key.
     *
     * @param string $key
     * @return void
     */
    public function delete(string $key): void
    {
        if ($this->queueStatePath === null) {
            unset($this->memory[$key]);
            return;
        }

        $this->withLock(function () use ($key): void {
            $state = $this->readStateUnlocked();

            if (!isset($state['payload']) || !\is_array($state['payload'])) {
                return;
            }

            unset($state['payload'][$key]);
            $this->writeStateUnlocked($state);
        });
    }

    /**
     * Clear all payload values.
     *
     * @return void
     */
    public function clear(): void
    {
        if ($this->queueStatePath === null) {
            $this->memory = [];
            return;
        }

        $this->withLock(function (): void {
            $state = $this->readStateUnlocked();
            unset($state['payload']);
            $this->writeStateUnlocked($state);
        });
    }

    /**
     * Execute callback while holding an exclusive lock on the queue file.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withLock(callable $fn)
    {
        if ($this->queueStatePath === null) {
            return $fn();
        }

        $lockPath = $this->queueStatePath . '.lock';

        $fh = @\fopen($lockPath, 'c+');
        if (!\is_resource($fh)) {
            throw new \RuntimeException("QueuePayload: failed opening lock file {$lockPath}");
        }

        try {
            if (!@\flock($fh, \LOCK_EX)) {
                throw new \RuntimeException("QueuePayload: failed acquiring lock {$lockPath}");
            }

            return $fn();
        } finally {
            @\flock($fh, \LOCK_UN);
            @\fclose($fh);

            \clearstatcache(true, $lockPath);

            if (\is_file($lockPath)) {
                @\unlink($lockPath);
                \clearstatcache(true, $lockPath);
            }
        }
    }

    /**
     * Read full queue state JSON without locking (caller must hold lock).
     *
     * @return array<string,mixed>
     */
    private function readStateUnlocked(): array
    {
        if ($this->queueStatePath === null) {
            return [];
        }

        $raw = @\file_get_contents($this->queueStatePath);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist full queue state JSON atomically without locking (caller must hold lock).
     *
     * @param array<string,mixed> $state
     * @return void
     */
    private function writeStateUnlocked(array $state): void
    {
        if ($this->queueStatePath === null) {
            return;
        }

        $json = \json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('QueuePayload: json_encode failed');
        }

        $tmp = $this->queueStatePath . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(6));

        if (@\file_put_contents($tmp, $json . \PHP_EOL, \LOCK_EX) === false) {
            throw new \RuntimeException("QueuePayload: failed writing temp file {$tmp}");
        }

        if (!@\rename($tmp, $this->queueStatePath)) {
            @\unlink($tmp);
            throw new \RuntimeException('QueuePayload: failed renaming temp file');
        }
    }

    /**
     * Extract the payload sub-array from a queue state array.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function readPayload(array $state): array
    {
        $payload = $state['payload'] ?? null;
        return \is_array($payload) ? $payload : [];
    }
}
