<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Http;

use rafalmasiarek\DashboardKitScheduler\Clock;

/**
 * Manages cron queue files used by the queue+redirect anti-timeout mechanism.
 *
 * Each queue is a JSON file in the cron_queues directory. All mutations are
 * serialized via an advisory flock on a "<id>.json.lock" file, matching the
 * convention used by QueuePayload.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class QueueManager
{
    /**
     * @var string
     */
    private string $dir;

    /**
     * @param string $storageDir Base storage directory (e.g. "<root>/storage").
     */
    public function __construct(string $storageDir)
    {
        $this->dir = \rtrim($storageDir, '/') . '/cron_queues';

        if (!\is_dir($this->dir)) {
            @\mkdir($this->dir, 0775, true);
        }
    }

    /**
     * Return the queue directory path.
     *
     * @return string
     */
    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Return the absolute path for a queue JSON file.
     *
     * @param string $id
     * @return string
     */
    public function path(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    /**
     * Create and persist a new queue file.
     *
     * @param string               $id
     * @param string               $secret
     * @param string[]             $tasks
     * @param array<string,string> $passthroughQuery
     * @param Clock                $clock
     * @param array<string,string> $runIdsByTask Optional run_id per task name.
     * @return array<string,mixed>
     */
    public function create(
        string $id,
        string $secret,
        array $tasks,
        array $passthroughQuery,
        Clock $clock,
        array $runIdsByTask = []
    ): array {
        $queue = [
            'id'                => $id,
            'secret'            => $secret,
            'passthrough_query' => $passthroughQuery,
            'created_at'        => $clock->now()->format(\DATE_ATOM),
            'finished_at'       => null,
            'tasks'             => \array_values(\array_map(
                function (string $name) use ($runIdsByTask): array {
                    $seededRunId = $runIdsByTask[$name] ?? '';

                    return [
                        'name'         => $name,
                        'queue_status' => 'pending',
                        'current_part' => 0,
                        'run_id'       => $seededRunId !== '' ? $seededRunId : \bin2hex(\random_bytes(16)),
                        'runs'         => [],
                    ];
                },
                $tasks
            )),
        ];

        $this->withLock($id, function () use ($id, $queue): void {
            \file_put_contents(
                $this->path($id),
                \json_encode($queue, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
            );
        });

        return $queue;
    }

    /**
     * Load a queue file.
     *
     * @param string $id
     * @return array<string,mixed>|null
     */
    public function load(string $id): ?array
    {
        return $this->withLock($id, function () use ($id): ?array {
            $path = $this->path($id);
            if (!\is_file($path)) {
                return null;
            }

            $json = \file_get_contents($path);
            if ($json === false || $json === '') {
                return null;
            }

            $data = \json_decode($json, true);
            return \is_array($data) ? $data : null;
        });
    }

    /**
     * Persist a queue file.
     *
     * @param array<string,mixed> $queue
     * @return void
     */
    public function save(array $queue): void
    {
        $id = (string) ($queue['id'] ?? '');
        if ($id === '') {
            return;
        }

        $this->withLock($id, function () use ($id, $queue): void {
            \file_put_contents(
                $this->path($id),
                \json_encode($queue, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
            );
        });
    }

    /**
     * Validate a queue token and return the queue if valid.
     *
     * @param string $queueId
     * @param string $token
     * @return array<string,mixed>|null
     */
    public function validateToken(string $queueId, string $token): ?array
    {
        $queue = $this->load($queueId);
        if ($queue === null) {
            return null;
        }

        $secret = (string) ($queue['secret'] ?? '');
        if ($secret === '' || $token === '') {
            return null;
        }

        if (!\hash_equals($secret, $token)) {
            return null;
        }

        return $queue;
    }

    /**
     * Execute callback while holding an exclusive lock on the queue file.
     *
     * @template T
     * @param string $id Queue ID.
     * @param callable():T $fn
     * @return T
     */
    public function withLock(string $id, callable $fn)
    {
        $lockPath = $this->path($id) . '.lock';

        $fh = @\fopen($lockPath, 'c+');
        if (!\is_resource($fh)) {
            throw new \RuntimeException("Failed opening queue lock file {$lockPath}");
        }

        try {
            if (!@\flock($fh, \LOCK_EX)) {
                throw new \RuntimeException("Failed acquiring queue lock {$lockPath}");
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
     * Remove internal queue/routing params from query params; normalize values to strings.
     *
     * @param array<string,mixed> $q
     * @return array<string,string>
     */
    public static function filterPassthrough(array $q): array
    {
        unset($q['queue'], $q['qt'], $q['index'], $q['part']);

        $out = [];
        foreach ($q as $k => $v) {
            if (!\is_string($k) || $k === '') {
                continue;
            }

            if (\is_array($v)) {
                $flat = '';
                foreach ($v as $vv) {
                    if (\is_scalar($vv) || $vv === null) {
                        $flat = (string) $vv;
                    }
                }
                $out[$k] = $flat;
                continue;
            }

            if (\is_scalar($v) || $v === null) {
                $out[$k] = (string) $v;
                continue;
            }
        }

        return $out;
    }

    /**
     * Build a "&"-prefixed query string from pass-through params.
     *
     * @param array<string,string> $passthrough
     * @return string
     */
    public static function buildPassthroughQs(array $passthrough): string
    {
        if ($passthrough === []) {
            return '';
        }

        $qs = \http_build_query($passthrough, '', '&', \PHP_QUERY_RFC3986);
        return $qs !== '' ? '&' . $qs : '';
    }

    /**
     * Export pass-through params into $_SERVER as CRON_JOB_* variables.
     *
     * @param array<string,string> $passthrough
     * @return void
     */
    public static function exportToServer(array $passthrough): void
    {
        foreach ($passthrough as $k => $v) {
            if (!\is_string($k) || $k === '') {
                continue;
            }

            $key = \strtoupper($k);
            $key = (string) \preg_replace('~[^A-Z0-9_]+~', '_', $key);
            $key = \trim($key, '_');

            if ($key === '') {
                continue;
            }

            $_SERVER['CRON_JOB_' . $key] = (string) $v;
        }
    }
}
