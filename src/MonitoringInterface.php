<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * Monitoring hooks for Scheduler.
 *
 * Implementations may send pings to HealthChecks.io, store metrics, etc.
 * All methods MUST swallow their own exceptions so monitoring never breaks scheduling.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
interface MonitoringInterface
{
    /**
     * Called right before running a task.
     *
     * @param string              $taskId
     * @param array<string,mixed> $context
     * @return void
     */
    public function onTaskRunStart(string $taskId, array $context = []): void;

    /**
     * Called after a successful task run.
     *
     * @param string              $taskId
     * @param array<string,mixed> $record  Full task run record from Scheduler::runTask().
     * @param array<string,mixed> $ctx
     * @return void
     */
    public function onTaskRunOk(string $taskId, array $record, array $ctx): void;

    /**
     * Called after a failed task run.
     *
     * @param string              $taskId
     * @param array<string,mixed> $record  Full task run record from Scheduler::runTask().
     * @param \Throwable          $e       Exception thrown by the task.
     * @param array<string,mixed> $ctx
     * @return void
     */
    public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $ctx): void;
}
