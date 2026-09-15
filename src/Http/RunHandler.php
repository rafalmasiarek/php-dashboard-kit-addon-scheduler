<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use rafalmasiarek\DashboardKitScheduler\Clock;
use rafalmasiarek\DashboardKitScheduler\Output\CronOutputBuilder;
use rafalmasiarek\DashboardKitScheduler\Scheduler;

/**
 * GET /cron — authenticated endpoint (SCHEDULER_TOKEN or Bearer).
 *
 * Without ?task=: discovers due tasks, creates a queue and 303-redirects to /cron/job.
 * With ?task=<id>: runs a single task directly; if the task signals multi-part continuation
 *   (next_part != null in result) or queue-init mode (queue_init=true), creates a queue
 *   and 303-redirects to /cron/job.
 *
 * Pass-through: all query params except queue/qt/index/part are forwarded to all /cron/job calls.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class RunHandler
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
     * Handle the run request.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $q           = $request->getQueryParams();
            $passthrough = QueueManager::filterPassthrough($q);
            $passQs      = QueueManager::buildPassthroughQs($passthrough);

            $taskId = isset($q['task']) ? \trim((string) $q['task']) : '';
            $force  = $this->qBool($q, 'force') ?? false;
            $options = ['verbose' => $q['verbose'] ?? false];

            // -----------------------------------------------------------------
            // Single task mode: /cron?task=<id>
            // -----------------------------------------------------------------
            if ($taskId !== '') {
                return $this->runSingleTask(
                    $request,
                    $response,
                    $taskId,
                    $force,
                    $passthrough,
                    $passQs,
                    $options
                );
            }

            // -----------------------------------------------------------------
            // All due tasks mode: /cron
            // -----------------------------------------------------------------
            $dueTasks = $this->scheduler->listDueTasks();

            if ($dueTasks === []) {
                return JsonResponder::ok($response, 'No scheduled tasks are due.', [
                    'executed' => [],
                    'queue'    => null,
                ]);
            }

            $queueId    = \bin2hex(\random_bytes(16));
            $queueToken = \bin2hex(\random_bytes(16));

            $queue = $this->queueManager->create($queueId, $queueToken, $dueTasks, $passthrough, $this->clock);

            $jobPath  = $this->jobPath($request);
            $firstUrl = $jobPath
                . '?queue=' . \urlencode($queueId)
                . '&index=0'
                . '&part=0'
                . '&qt=' . \urlencode($queueToken)
                . $passQs;

            $response = JsonResponder::ok($response, 'Cron queue created.', [
                'queue_id'          => $queueId,
                'task_count'        => \count($queue['tasks']),
                'next'              => $firstUrl,
                'passthrough_query' => $passthrough,
            ]);

            return $response->withHeader('Location', $firstUrl)->withStatus(303);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, 'Failed to execute scheduled tasks.', 500, 'CRON_EXECUTION_FAILED');
        }
    }

    /**
     * Run a single named task, handling queue-init and continuation modes.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @param string                 $taskId
     * @param bool                   $force
     * @param array<string,string>   $passthrough
     * @param string                 $passQs
     * @param array<string,mixed>    $options Output options (e.g. ['verbose' => true]).
     * @return ResponseInterface
     */
    private function runSingleTask(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $taskId,
        bool $force,
        array $passthrough,
        string $passQs,
        array $options
    ): ResponseInterface {
        $runId  = \bin2hex(\random_bytes(16));
        $ctx    = ['mode' => 'direct', 'run_id' => $runId];

        QueueManager::exportToServer($passthrough);

        foreach ($passthrough as $k => $v) {
            if (!\is_string($k) || $k === '') {
                continue;
            }
            if (!\array_key_exists($k, $ctx)) {
                $ctx[$k] = $v;
            }
        }

        $record    = $this->scheduler->runTask($taskId, $force, $ctx);
        $result    = $record['result'] ?? null;
        $resultArr = \is_array($result) ? $result : null;

        // Queue-init: task signals it wants to start a queue at part=1.
        if ($record['status'] === 'ok' && $this->isQueueInit($resultArr)) {
            return $this->createQueue(
                $request,
                $response,
                $taskId,
                $runId,
                $passthrough,
                $passQs,
                1,
                $record,
                'Cron queue created (queue-init).',
                $options
            );
        }

        // Continuation: task wants follow-up parts.
        if (
            $record['status'] === 'ok'
            && \is_array($resultArr)
            && \array_key_exists('done', $resultArr)
            && $resultArr['done'] === false
            && \array_key_exists('next_part', $resultArr)
            && $resultArr['next_part'] !== null
        ) {
            $nextPart = \max(0, (int) $resultArr['next_part']);

            return $this->createQueue(
                $request,
                $response,
                $taskId,
                $runId,
                $passthrough,
                $passQs,
                $nextPart,
                $record,
                'Cron queue created (continuation).',
                $options
            );
        }

        return JsonResponder::ok($response, 'Cron task executed.', \array_replace(
            CronOutputBuilder::build((string) $record['name'], 'direct', [$record], null, $options),
            ['forced' => $force]
        ));
    }

    /**
     * Create a queue for a single task and redirect to the first job.
     *
     * @param ServerRequestInterface  $request
     * @param ResponseInterface       $response
     * @param string                  $taskId
     * @param string                  $runId
     * @param array<string,string>    $passthrough
     * @param string                  $passQs
     * @param int                     $firstPart
     * @param array<string,mixed>     $initialRecord
     * @param string                  $message
     * @param array<string,mixed>     $options Output options (e.g. ['verbose' => true]).
     * @return ResponseInterface
     */
    private function createQueue(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $taskId,
        string $runId,
        array $passthrough,
        string $passQs,
        int $firstPart,
        array $initialRecord,
        string $message,
        array $options
    ): ResponseInterface {
        $queueId    = \bin2hex(\random_bytes(16));
        $queueToken = \bin2hex(\random_bytes(16));

        $queue = $this->queueManager->create(
            $queueId,
            $queueToken,
            [$taskId],
            $passthrough,
            $this->clock,
            [$taskId => $runId]
        );

        $jobPath = $this->jobPath($request);
        $nextUrl = $jobPath
            . '?queue=' . \urlencode($queueId)
            . '&index=0'
            . '&part=' . $firstPart
            . '&qt=' . \urlencode($queueToken)
            . $passQs;

        $response = JsonResponder::ok($response, $message, [
            'queue_id'          => $queueId,
            'task_count'        => \count($queue['tasks']),
            'next'              => $nextUrl,
            'passthrough_query' => $passthrough,
            'initial'           => CronOutputBuilder::build(
                (string) $initialRecord['name'],
                'direct',
                [$initialRecord],
                null,
                $options
            ),
        ]);

        return $response->withHeader('Location', $nextUrl)->withStatus(303);
    }

    /**
     * Detect queue-init result: task returns ['queue_init' => true, 'total_parts' => N>=2].
     *
     * @param array<string,mixed>|null $result
     * @return bool
     */
    private function isQueueInit(?array $result): bool
    {
        if ($result === null) {
            return false;
        }
        if (($result['queue_init'] ?? false) !== true) {
            return false;
        }
        return (int) ($result['total_parts'] ?? 0) >= 2;
    }

    /**
     * Derive the /cron/job URL path from the current request.
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    private function jobPath(ServerRequestInterface $request): string
    {
        $path = \rtrim($request->getUri()->getPath(), '/');

        // /scheduler/run → /scheduler/job
        // /v1/cron       → /v1/cron/job  (API plugin usage)
        if (\str_ends_with($path, '/run')) {
            return \substr($path, 0, -3) . 'job';
        }

        return $path . '/job';
    }

    /**
     * Read a boolean-ish query parameter.
     *
     * @param array<string,mixed> $q
     * @param string              $key
     * @return bool|null
     */
    private function qBool(array $q, string $key): ?bool
    {
        if (!\array_key_exists($key, $q)) {
            return null;
        }
        $v = $q[$key];
        if (\is_array($v)) {
            $v = \end($v);
        }
        $s = \strtolower(\trim((string) $v));
        return \in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
