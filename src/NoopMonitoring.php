<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * Default no-op monitoring implementation.
 *
 * Safe to use in all environments where monitoring is not configured.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class NoopMonitoring implements MonitoringInterface
{
    /**
     * {@inheritdoc}
     */
    public function onTaskRunStart(string $taskId, array $context = []): void
    {
        // Intentionally no-op.
    }

    /**
     * {@inheritdoc}
     */
    public function onTaskRunOk(string $taskId, array $record, array $context = []): void
    {
        // Intentionally no-op.
    }

    /**
     * {@inheritdoc}
     */
    public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $context = []): void
    {
        // Intentionally no-op.
    }
}
