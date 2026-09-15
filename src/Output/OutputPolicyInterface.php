<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Output;

/**
 * Builds a normalized result payload from one or more scheduler run records.
 *
 * A policy decides which sections belong in "result" based on output options
 * (currently just "verbose", read from the request's passthrough query).
 *
 * @package rafalmasiarek\DashboardKitScheduler\Output
 */
interface OutputPolicyInterface
{
    /**
     * Build the normalized result payload for a task.
     *
     * @param string $taskName Registered task name (e.g. "my_module.my_task").
     * @param array<int,array<string,mixed>> $runs Run records in execution order, oldest first.
     * @param array<string,mixed> $options Output options (e.g. ['verbose' => true]).
     *
     * @return array<string,mixed>
     */
    public function buildResult(string $taskName, array $runs, array $options = []): array;
}
