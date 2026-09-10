<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * Object wrapper passed into task callbacks.
 *
 * Provides named, typed access to task runtime data:
 * - $context->taskName  — registered task name
 * - $context->data      — raw context array from the queue/HTTP request
 * - $context->opt       — final merged options (single source of truth)
 * - $context->state     — scheduler state store (last_run, etc.)
 * - $context->payload   — per-queue payload (persisted when queue_state_path present)
 * - $context->clock     — shared Clock instance
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class TaskContext
{
    /**
     * @var string
     */
    public string $taskName;

    /**
     * @var array<string,mixed>
     */
    public array $data;

    /**
     * Final merged options for this run (config base + context overrides).
     *
     * @var array<string,mixed>
     */
    public array $opt;

    /**
     * @var SchedulerStateStore
     */
    public SchedulerStateStore $state;

    /**
     * @var QueuePayload
     */
    public QueuePayload $payload;

    /**
     * @var Clock
     */
    public Clock $clock;

    /**
     * @param string              $taskName
     * @param array<string,mixed> $data
     * @param array<string,mixed> $opt
     * @param SchedulerStateStore $state
     * @param QueuePayload        $payload
     * @param Clock               $clock
     */
    public function __construct(
        string $taskName,
        array $data,
        array $opt,
        SchedulerStateStore $state,
        QueuePayload $payload,
        Clock $clock
    ) {
        $this->taskName = $taskName;
        $this->data     = $data;
        $this->opt      = $opt;
        $this->state    = $state;
        $this->payload  = $payload;
        $this->clock    = $clock;
    }

    /**
     * Convenience getter for raw context keys.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    /**
     * Merge options with "context wins" semantics.
     *
     * Null and empty-string values do not override base.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    public static function mergeOpt(array $base, array $ctx): array
    {
        foreach ($ctx as $k => $v) {
            if (!\is_string($k) || $k === '') {
                continue;
            }

            if ($v === null) {
                continue;
            }

            if (\is_string($v) && \trim($v) === '') {
                continue;
            }

            $base[$k] = $v;
        }

        return $base;
    }
}
