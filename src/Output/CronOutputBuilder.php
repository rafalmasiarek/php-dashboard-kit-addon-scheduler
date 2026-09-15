<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Output;

/**
 * Builds the normalized response payload shared by RunHandler and JobHandler,
 * regardless of whether the task ran directly or through a queue.
 *
 * @package rafalmasiarek\DashboardKitScheduler\Output
 */
final class CronOutputBuilder
{
    /**
     * Build the normalized payload for a task's run history.
     *
     * @param string $taskName
     * @param string $mode "direct" or "queue".
     * @param array<int,array<string,mixed>> $runs Run records in execution order, oldest first.
     * @param array<string,mixed>|null $queueMeta Queue identifiers (id/index/part), null outside a queue.
     * @param array<string,mixed> $options Output options (e.g. ['verbose' => true]).
     *
     * @return array<string,mixed>
     */
    public static function build(
        string $taskName,
        string $mode,
        array $runs,
        ?array $queueMeta,
        array $options = []
    ): array {
        $policy = OutputPolicyResolver::for($taskName);

        $first = $runs[0] ?? null;
        $last  = $runs !== [] ? $runs[\count($runs) - 1] : null;

        $durationMs = 0;
        foreach ($runs as $run) {
            if (\is_array($run)) {
                $durationMs += (int) ($run['duration_ms'] ?? 0);
            }
        }

        $status = \is_array($last) ? (string) ($last['status'] ?? 'skipped') : 'skipped';

        return [
            'task_name' => $taskName,
            'mode'      => $mode,
            'queue'     => $queueMeta,
            'task'      => [
                'status'      => $status,
                'started_at'  => \is_array($first) ? ($first['started_at'] ?? null) : null,
                'finished_at' => \is_array($last) ? ($last['finished_at'] ?? null) : null,
                'duration_ms' => $durationMs,
                'error'       => \is_array($last) ? ($last['error'] ?? null) : null,
            ],
            'result' => $policy->buildResult($taskName, $runs, $options),
        ];
    }
}
