<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

use Psr\Log\LoggerInterface;

/**
 * Logs task execution lifecycle events to a PSR-3 logger (typically logger.system).
 *
 * Auto-wired by SchedulerAddon when logger.system is available in the DI container
 * and no explicit MonitoringInterface binding exists.
 *
 * Emitted events:
 *   task.start  — info    — emitted right before a task begins execution
 *   task.ok     — info    — emitted after successful completion
 *   task.locked — warning — emitted when concurrent execution was blocked by FileLockMiddleware
 *   task.fail   — error   — emitted when the task threw an unhandled exception
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class SystemLogMonitoring implements MonitoringInterface
{
    /**
     * @param LoggerInterface $logger Logger to write lifecycle events to.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function onTaskRunStart(string $taskId, array $context = []): void
    {
        $this->logger->info('task.start', ['task' => $taskId]);
    }

    /**
     * {@inheritdoc}
     */
    public function onTaskRunOk(string $taskId, array $record, array $ctx = []): void
    {
        $this->logger->info('task.ok', [
            'task'        => $taskId,
            'duration_ms' => $record['duration_ms'] ?? null,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $ctx = []): void
    {
        $errorType = (string) ($record['error']['type'] ?? '');

        if (\str_ends_with($errorType, 'TaskLockedException')) {
            $this->logger->warning('task.locked', [
                'task'        => $taskId,
                'duration_ms' => $record['duration_ms'] ?? null,
            ]);
            return;
        }

        $this->logger->error('task.fail', [
            'task'        => $taskId,
            'duration_ms' => $record['duration_ms'] ?? null,
            'error'       => $e->getMessage(),
        ]);
    }
}
