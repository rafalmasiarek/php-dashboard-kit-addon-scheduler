<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use rafalmasiarek\DashboardKitScheduler\Clock;
use rafalmasiarek\DashboardKitScheduler\Scheduler;

/**
 * GET /cron/job — internal endpoint (queue token required).
 *
 * Executes one task step from a queue created by RunHandler.
 * After execution either:
 * - 303-redirects to the next part of the same task (multi-part),
 * - 303-redirects to the next pending task in the queue,
 * - returns 200 with "Cron queue completed." when all tasks are done.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class JobHandler
{
    /**
     * @var Scheduler
     */
    private Scheduler $scheduler;

    /**
     * @var Clock
     */
    private Clock $clock;

    /**
     * @var QueueManager
     */
    private QueueManager $queueManager;

    /**
     * @param Scheduler    $scheduler
     * @param Clock        $clock
     * @param QueueManager $queueManager
     */
    public function __construct(Scheduler $scheduler, Clock $clock, QueueManager $queueManager)
    {
        $this->scheduler    = $scheduler;
        $this->clock        = $clock;
        $this->queueManager = $queueManager;
    }

    /**
     * Handle a single job step from a queue.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = $request->getQueryParams();

        $uri     = $request->getUri();
        $jobPath = \rtrim($uri->getPath(), '/');

        $queueId  = isset($q['queue']) ? (string) $q['queue'] : '';
        $token    = isset($q['qt']) ? (string) $q['qt'] : '';
        $index    = \max(0, (int) ($q['index'] ?? 0));
        $part     = \max(0, (int) ($q['part'] ?? 0));

        if ($queueId === '' || $token === '') {
            return JsonResponder::error($response, 'Missing queue or qt parameter.', 400, 'CRON_QUEUE_TOKEN_REQUIRED');
        }

        $queue = $this->queueManager->validateToken($queueId, $token);
        if ($queue === null) {
            return JsonResponder::error($response, 'Invalid queue or token.', 403, 'CRON_QUEUE_INVALID_TOKEN');
        }

        // Rebuild pass-through query from queue (source of truth).
        $passthrough = [];
        if (isset($queue['passthrough_query']) && \is_array($queue['passthrough_query'])) {
            foreach ($queue['passthrough_query'] as $k => $v) {
                if (\is_string($k) && (\is_string($v) || $v === null || \is_numeric($v))) {
                    $passthrough[$k] = (string) $v;
                }
            }
        }
        $passQs = QueueManager::buildPassthroughQs($passthrough);

        $_SERVER['CRON_JOB_PART'] = (string) $part;
        QueueManager::exportToServer($passthrough);

        $tasks = $queue['tasks'] ?? [];
        if (!\is_array($tasks) || !isset($tasks[$index])) {
            return JsonResponder::error($response, 'Task index out of range for the queue.', 400, 'CRON_TASK_INDEX_OUT_OF_RANGE');
        }

        $task = $tasks[$index];
        $name = (string) ($task['name'] ?? '');
        if ($name === '') {
            return JsonResponder::error($response, 'Task name missing in queue definition.', 500, 'CRON_TASK_NAME_MISSING');
        }

        $runId = (string) ($queue['tasks'][$index]['run_id'] ?? '');
        if ($runId === '') {
            $runId = \bin2hex(\random_bytes(16));
            $queue['tasks'][$index]['run_id'] = $runId;
            $this->queueManager->save($queue);
        }

        $ctx = [
            'part'             => $part,
            'queue_id'         => $queueId,
            'queue_index'      => $index,
            'queue_part'       => $part,
            'mode'             => 'queue',
            'run_id'           => $runId,
            'queue_state_path' => $this->queueManager->path($queueId),
        ];

        foreach ($passthrough as $k => $v) {
            if (!\is_string($k) || $k === '') {
                continue;
            }
            if (!\array_key_exists($k, $ctx)) {
                $ctx[$k] = $v;
            }
        }

        $record = $this->scheduler->runTask($name, false, $ctx);

        // Reload queue in case QueuePayload wrote to the file during execution.
        $freshQueue = $this->queueManager->load($queueId);
        if (\is_array($freshQueue)) {
            $queue = $freshQueue;
        }

        if (!isset($queue['tasks'][$index]['runs']) || !\is_array($queue['tasks'][$index]['runs'])) {
            $queue['tasks'][$index]['runs'] = [];
        }
        $queue['tasks'][$index]['runs'][]         = $record;
        $queue['tasks'][$index]['last_run_status'] = $record['status'];
        $queue['tasks'][$index]['last_run_error']  = $record['error'];
        $queue['tasks'][$index]['last_run_result'] = $record['result'];
        $queue['tasks'][$index]['current_part']    = $part;

        $result    = $record['result'] ?? null;
        $isStepJob = \is_array($result);
        $isDone    = true;
        $nextPart  = null;

        if ($isStepJob) {
            if (\array_key_exists('done', $result)) {
                $isDone = (bool) $result['done'];
            }
            if (\array_key_exists('next_part', $result) && $result['next_part'] !== null) {
                $nextPart = (int) $result['next_part'];
            }
        }

        // Task errored → mark finished, advance queue.
        if ($record['status'] === 'error') {
            $queue['tasks'][$index]['queue_status'] = 'finished';
        } elseif ($isStepJob && !$isDone && $nextPart !== null) {
            // Continue same task at next part.
            $queue['tasks'][$index]['queue_status'] = 'running';
            $queue['tasks'][$index]['current_part'] = $nextPart;

            $this->queueManager->save($queue);

            $nextUrl = $jobPath
                . '?queue=' . \urlencode($queueId)
                . '&index=' . $index
                . '&part=' . $nextPart
                . '&qt=' . \urlencode($token)
                . $passQs;

            $runs = $queue['tasks'][$index]['runs'];

            $response = JsonResponder::ok($response, 'Cron task step executed, continuing with next part.', [
                'task_name' => $name,
                'task'      => $this->buildTaskSummary($record),
                'next'      => $nextUrl,
                'queue'     => ['id' => $queueId, 'index' => $index, 'part' => $part],
            ]);

            return $response->withHeader('Location', $nextUrl)->withStatus(303);
        } else {
            $queue['tasks'][$index]['queue_status'] = 'finished';
        }

        // Find next pending task.
        $nextIndex = null;
        foreach ($queue['tasks'] as $i => $t) {
            if (($t['queue_status'] ?? 'pending') !== 'finished') {
                $nextIndex = (int) $i;
                break;
            }
        }

        if ($nextIndex !== null) {
            $queue['tasks'][$nextIndex]['current_part'] = 0;
            $this->queueManager->save($queue);

            $nextUrl = $jobPath
                . '?queue=' . \urlencode($queueId)
                . '&index=' . $nextIndex
                . '&part=0'
                . '&qt=' . \urlencode($token)
                . $passQs;

            $response = JsonResponder::ok($response, 'Cron task executed, continuing with next task.', [
                'task_name' => $name,
                'task'      => $this->buildTaskSummary($record),
                'next'      => $nextUrl,
                'queue'     => ['id' => $queueId, 'index' => $nextIndex, 'part' => $part],
            ]);

            return $response->withHeader('Location', $nextUrl)->withStatus(303);
        }

        // All tasks done.
        $queue['finished_at'] = $this->clock->now()->format(\DATE_ATOM);
        $this->queueManager->save($queue);

        return JsonResponder::ok($response, 'Cron queue completed.', [
            'task_name'    => $name,
            'task'         => $this->buildTaskSummary($record),
            'queue_status' => 'completed',
            'queue'        => ['id' => $queueId, 'index' => $index, 'part' => $part],
        ]);
    }

    /**
     * Build a concise task summary from a runTask() record.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function buildTaskSummary(array $record): array
    {
        return [
            'status'      => $record['status'],
            'started_at'  => $record['started_at'],
            'finished_at' => $record['finished_at'],
            'duration_ms' => $record['duration_ms'],
            'error'       => $record['error'],
        ];
    }
}
