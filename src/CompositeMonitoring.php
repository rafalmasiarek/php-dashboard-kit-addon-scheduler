<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * Delegates monitoring events to multiple MonitoringInterface implementations.
 *
 * Used internally by SchedulerAddon to chain SystemLogMonitoring with any
 * user-supplied MonitoringInterface binding, so both receive every lifecycle event.
 * Each delegate's exceptions are suppressed individually so one failing monitor
 * cannot prevent others from being called.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class CompositeMonitoring implements MonitoringInterface
{
    /**
     * @param MonitoringInterface[] $monitors Ordered list of monitors to invoke.
     */
    public function __construct(private readonly array $monitors)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function onTaskRunStart(string $taskId, array $context = []): void
    {
        foreach ($this->monitors as $monitor) {
            try {
                $monitor->onTaskRunStart($taskId, $context);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onTaskRunOk(string $taskId, array $record, array $ctx = []): void
    {
        foreach ($this->monitors as $monitor) {
            try {
                $monitor->onTaskRunOk($taskId, $record, $ctx);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $ctx = []): void
    {
        foreach ($this->monitors as $monitor) {
            try {
                $monitor->onTaskRunFail($taskId, $record, $e, $ctx);
            } catch (\Throwable) {
            }
        }
    }
}
