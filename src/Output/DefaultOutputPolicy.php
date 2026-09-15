<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Output;

/**
 * Default, compact task result policy. Used for any task without a
 * registered task-specific policy.
 *
 * @package rafalmasiarek\DashboardKitScheduler\Output
 */
final class DefaultOutputPolicy extends AbstractOutputPolicy
{
    /**
     * {@inheritDoc}
     */
    protected function buildStep(array $run): array
    {
        return [
            'started_at'  => $run['started_at'] ?? null,
            'finished_at' => $run['finished_at'] ?? null,
            'duration_ms' => (int) ($run['duration_ms'] ?? 0),
            'status'      => (string) ($run['status'] ?? 'unknown'),
            'result'      => $run['result'] ?? null,
            'error'       => $run['error'] ?? null,
        ];
    }
}
