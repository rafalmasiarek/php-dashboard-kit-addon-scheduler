<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

use Cron\CronExpression;
use rafalmasiarek\DashboardKitScheduler\Exception\TaskLockedException;
use rafalmasiarek\DashboardKitScheduler\Middleware\FileLockMiddleware;

/**
 * In-process cron-expression task scheduler.
 *
 * - Stores last-run timestamps via a pluggable SchedulerStateStore.
 * - Prevents duplicate executions within the same minute.
 * - Supports catch-up of missed runs via dragonmantank/cron-expression.
 * - Tasks can carry a per-task middleware pipeline (e.g. file locks).
 * - Tasks are indexed by name; registration is idempotent within a request.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
class Scheduler
{
    /**
     * Registered tasks indexed by task name.
     *
     * @var array<string,array{
     *   expression:string,
     *   callback:callable,
     *   middlewares:array<int,TaskMiddlewareInterface>,
     *   cron:?CronExpression,
     *   opt:array<string,mixed>,
     *   allow_concurrent:bool
     * }>
     */
    private array $tasks = [];

    /**
     * When true, tasks are non-overlapping by default.
     *
     * @var bool
     */
    private bool $defaultNoOverlap = true;

    /**
     * Directory for per-task lock files.
     *
     * @var string
     */
    private string $locksDir;

    /**
     * Pluggable state store for last-run timestamps.
     *
     * @var SchedulerStateStore
     */
    private SchedulerStateStore $store;

    /**
     * Optional monitoring implementation.
     *
     * @var MonitoringInterface
     */
    private MonitoringInterface $monitoring;

    /**
     * Shared time source.
     *
     * @var Clock
     */
    private Clock $clock;

    /**
     * Status used when a task is skipped due to a lock.
     */
    private const LOCKED_TASK_STATUS = 'error';

    /**
     * Create a new Scheduler instance.
     *
     * When $store is null, a FileStateStore is created using $runtimeDir
     * (or the SCHEDULER_STATE_DIR environment variable, falling back to "<package-dir>/../storage").
     *
     * @param SchedulerStateStore|null $store            Pluggable state store.
     * @param string|null              $runtimeDir       Path for the default FileStateStore.
     * @param mixed                    $timezone         Timezone ID string or DateTimeZone.
     * @param MonitoringInterface|null $monitoring       Monitoring implementation (default: NoopMonitoring).
     * @param Clock|null               $clock            Shared time source.
     * @param string|null              $locksDir         Directory for lock files.
     * @param bool                     $defaultNoOverlap Whether tasks are non-overlapping by default.
     */
    public function __construct(
        ?SchedulerStateStore $store = null,
        ?string $runtimeDir = null,
        mixed $timezone = null,
        ?MonitoringInterface $monitoring = null,
        ?Clock $clock = null,
        ?string $locksDir = null,
        bool $defaultNoOverlap = true
    ) {
        if ($store !== null) {
            $this->store = $store;
        } else {
            $runtimeDir  = $runtimeDir ?? ($_ENV['SCHEDULER_STATE_DIR'] ?? (__DIR__ . '/../storage'));
            $this->store = new FileStateStore($runtimeDir);
        }

        $tzId = '';
        if (\is_string($timezone)) {
            $tzId = \trim($timezone);
        } elseif ($timezone instanceof \DateTimeZone) {
            $tzId = $timezone->getName();
        }

        $this->clock = $clock instanceof Clock ? $clock : new Clock($tzId, 'UTC');

        $this->monitoring       = $monitoring ?? new NoopMonitoring();
        $this->defaultNoOverlap = $defaultNoOverlap;

        $resolvedRuntimeDir = $runtimeDir ?? ($_ENV['SCHEDULER_STATE_DIR'] ?? (__DIR__ . '/../storage'));

        $locksDir = $locksDir !== null ? \rtrim($locksDir, '/') : '';
        if ($locksDir === '') {
            $locksDir = \rtrim((string) $resolvedRuntimeDir, '/') . '/locks';
        }

        $this->locksDir = $locksDir;

        if (!\is_dir($this->locksDir)) {
            @\mkdir($this->locksDir, 0775, true);
        }
    }

    /**
     * Expose the shared state store.
     *
     * @return SchedulerStateStore
     */
    public function state(): SchedulerStateStore
    {
        return $this->store;
    }

    /**
     * Build a QueuePayload accessor from a context array.
     *
     * @param array<string,mixed> $ctx
     * @return QueuePayload
     */
    public function payloadFrom(array $ctx): QueuePayload
    {
        return QueuePayload::fromContext($ctx);
    }

    /**
     * Register a task.
     *
     * A FileLockMiddleware is prepended automatically when $defaultNoOverlap is true
     * and $allowConcurrent is false.
     *
     * @param string              $expression      Cron expression (e.g. "* * * * *").
     * @param callable            $callback        Task callback; receives TaskContext when it accepts >= 1 parameter.
     * @param string              $name            Unique task name.
     * @param array<string,mixed> $optBase         Base options merged with runtime context at execution.
     * @param bool                $allowConcurrent When true, disables the default no-overlap lock.
     * @return void
     */
    public function add(
        string $expression,
        callable $callback,
        string $name,
        array $optBase = [],
        bool $allowConcurrent = false
    ): void {
        $expression  = \trim($expression);
        $cron        = $this->buildCronExpression($expression, $name);
        $middlewares = [];

        if ($this->defaultNoOverlap && !$allowConcurrent) {
            $middlewares[] = new FileLockMiddleware($this->locksDir, $this->clock);
        }

        $this->tasks[$name] = [
            'expression'       => $expression,
            'callback'         => $callback,
            'middlewares'      => $middlewares,
            'cron'             => $cron,
            'opt'              => $optBase,
            'allow_concurrent' => $allowConcurrent,
        ];
    }

    /**
     * Register a task with an explicit middleware list.
     *
     * When $defaultNoOverlap is true and $allowConcurrent is false, a FileLockMiddleware
     * is prepended before the provided list.
     *
     * @param string                          $expression
     * @param callable                        $callback
     * @param string                          $name
     * @param array<int,TaskMiddlewareInterface> $middlewares
     * @param array<string,mixed>             $optBase
     * @param bool                            $allowConcurrent
     * @return void
     */
    public function addWithMiddlewares(
        string $expression,
        callable $callback,
        string $name,
        array $middlewares,
        array $optBase = [],
        bool $allowConcurrent = false
    ): void {
        $expression = \trim($expression);
        $cron       = $this->buildCronExpression($expression, $name);

        $list = [];

        if ($this->defaultNoOverlap && !$allowConcurrent) {
            $list[] = new FileLockMiddleware($this->locksDir, $this->clock);
        }

        foreach ($middlewares as $mw) {
            if ($mw instanceof TaskMiddlewareInterface) {
                $list[] = $mw;
            }
        }

        $this->tasks[$name] = [
            'expression'       => $expression,
            'callback'         => $callback,
            'middlewares'      => \array_values($list),
            'cron'             => $cron,
            'opt'              => $optBase,
            'allow_concurrent' => $allowConcurrent,
        ];
    }

    /**
     * List names of tasks that are due at the given time.
     *
     * @param \DateTimeInterface|null $now
     * @return string[]
     */
    public function listDueTasks(?\DateTimeInterface $now = null): array
    {
        $nowDt = $now ? $this->clock->fromInterface($now) : $this->clock->now();

        $due = [];
        foreach ($this->tasks as $name => $_task) {
            if ($this->isDueOrMissed($name, $nowDt)) {
                $due[] = $name;
            }
        }

        return $due;
    }

    /**
     * Describe all registered tasks with last-run state and due flag.
     *
     * @param \DateTimeInterface|null $now
     * @return array<string,array{name:string,expression:string,last_run_ts:int|null,last_run_at:string|null,is_due:bool}>
     */
    public function describeTasks(?\DateTimeInterface $now = null): array
    {
        $nowDt = $now ? $this->clock->fromInterface($now) : $this->clock->now();

        $out = [];
        foreach ($this->tasks as $name => $task) {
            $expression = (string) ($task['expression'] ?? '');
            $lastTs     = $this->lastRun($name);

            $lastAt = null;
            if ($lastTs !== null && $lastTs > 0) {
                $lastAt = $this->clock->formatAtomFromTimestamp($lastTs);
            }

            $out[$name] = [
                'name'        => $name,
                'expression'  => $expression,
                'last_run_ts' => $lastTs,
                'last_run_at' => $lastAt,
                'is_due'      => $this->isDueOrMissed($name, $nowDt),
            ];
        }

        return $out;
    }

    /**
     * Run all due tasks and return structured records.
     *
     * @return array<int,array{name:string,started_at:string,finished_at:string,duration_ms:int,status:string,result:mixed,error:array{type:string,message:string}|null}>
     */
    public function runDueTasksDetailed(): array
    {
        $records = [];

        foreach ($this->tasks as $name => $_task) {
            $res = $this->runTask($name, false, null);

            if (($res['status'] ?? 'skipped') === 'skipped') {
                continue;
            }

            $records[] = [
                'name'        => (string) $res['name'],
                'started_at'  => (string) $res['started_at'],
                'finished_at' => (string) $res['finished_at'],
                'duration_ms' => (int) $res['duration_ms'],
                'status'      => (string) $res['status'],
                'result'      => $res['result'] ?? null,
                'error'       => $res['error'] ?? null,
            ];
        }

        return $records;
    }

    /**
     * Run all due tasks and return names of successfully executed tasks.
     *
     * @return string[]
     */
    public function runDueTasks(): array
    {
        $executed = [];

        foreach ($this->tasks as $name => $_task) {
            $res = $this->runTask($name, false, null);
            if (($res['status'] ?? 'skipped') === 'ok') {
                $executed[] = $name;
            }
        }

        return $executed;
    }

    /**
     * Execute a single task run.
     *
     * When $force is false the task runs only if due (or allowed step execution via context).
     * last_started_at is stamped early to reduce minute-later re-trigger attempts.
     *
     * @param string                   $name
     * @param bool                     $force   When true, bypasses due-check and does not persist state.
     * @param array<string,mixed>|null $context Runtime context (queue params, passthrough query params, etc.).
     * @return array<string,mixed>
     */
    public function runTask(string $name, bool $force = false, ?array $context = null): array
    {
        $now       = $this->clock->now();
        $startedAt = $this->clock->now();
        $t0        = \microtime(true);
        $ctx       = $context ?? [];

        if (!isset($this->tasks[$name]['expression'], $this->tasks[$name]['callback'])) {
            $nowIso = $this->clock->formatAtom($now);

            return [
                'name'        => $name,
                'started_at'  => $nowIso,
                'finished_at' => $nowIso,
                'duration_ms' => 0,
                'status'      => 'skipped',
                'result'      => null,
                'error'       => [
                    'type'    => 'InvalidTask',
                    'message' => 'Task not registered or missing expression/callback',
                ],
                'context'    => $ctx,
                'monitoring' => null,
            ];
        }

        $cb = $this->tasks[$name]['callback'];

        $statelessForce = ($force === true);

        $allowStepExecution = false;
        if (
            isset($ctx['mode'], $ctx['part'])
            && $ctx['mode'] === 'queue'
            && (int) $ctx['part'] > 0
        ) {
            $allowStepExecution = true;
        }

        $isStepJobGate =
            \is_array($ctx)
            && (($ctx['mode'] ?? '') === 'queue')
            && \array_key_exists('part', $ctx)
            && (int) $ctx['part'] > 0;

        if (!$statelessForce) {
            $isDue = $this->isDueOrMissed($name, $now);

            if (!$force && !$isDue && !$allowStepExecution) {
                $nowIso = $this->clock->formatAtom($now);

                return [
                    'name'        => $name,
                    'started_at'  => $nowIso,
                    'finished_at' => $nowIso,
                    'duration_ms' => 0,
                    'status'      => 'skipped',
                    'result'      => null,
                    'error'       => null,
                    'context'     => $ctx,
                    'monitoring'  => null,
                ];
            }

            if (!$isStepJobGate) {
                $this->stampStartedAt($name, $startedAt);
            }
        }

        $previousPart     = $_SERVER['CRON_JOB_PART'] ?? null;
        $hasPartInContext  = \array_key_exists('part', $ctx);
        $isQueueMode      = (($ctx['mode'] ?? '') === 'queue');

        if ($hasPartInContext && $isQueueMode) {
            $_SERVER['CRON_JOB_PART'] = (int) $ctx['part'];
        }

        $optBase = [];
        if (isset($this->tasks[$name]['opt']) && \is_array($this->tasks[$name]['opt'])) {
            $optBase = $this->tasks[$name]['opt'];
        }
        $opt = $this->buildRuntimeOptions($optBase, $ctx);

        $payload     = $this->payloadFrom($ctx);
        $taskContext = new TaskContext($name, $ctx, $opt, $this->store, $payload, $this->clock);

        try {
            $this->monitoring->onTaskRunStart($name, $ctx);
        } catch (\Throwable) {
            // Monitoring must never break scheduler.
        }

        $status               = 'ok';
        $result               = null;
        $errArr               = null;
        $shouldClearStartedAt = false;

        try {
            $result               = $this->executeWithMiddlewares($name, $cb, $ctx, $taskContext);
            $shouldClearStartedAt = true;
        } catch (TaskLockedException $e) {
            $status = self::LOCKED_TASK_STATUS;

            $errArr = [
                'type'    => $e::class,
                'message' => $e->getMessage(),
            ];

            $shouldClearStartedAt = false;
        } catch (\Throwable $e) {
            $status = 'error';

            $errArr = [
                'type'    => $e::class,
                'message' => $e->getMessage(),
            ];

            $shouldClearStartedAt = true;
        } finally {
            if ($hasPartInContext && $isQueueMode) {
                if ($previousPart !== null) {
                    $_SERVER['CRON_JOB_PART'] = $previousPart;
                } else {
                    unset($_SERVER['CRON_JOB_PART']);
                }
            }
        }

        if (!$statelessForce && !$isStepJobGate && $status !== 'skipped') {
            $expr = $this->getCronForTask($name);
            $this->markAsRun($name, $expr, $startedAt);
        }

        if (!$statelessForce && !$isStepJobGate && $shouldClearStartedAt) {
            $this->clearStartedAt($name);
        }

        $finishedAt = $this->clock->now();
        $durationMs = (int) \round((\microtime(true) - $t0) * 1000);

        $record = [
            'name'        => $name,
            'started_at'  => $this->clock->formatAtom($startedAt),
            'finished_at' => $this->clock->formatAtom($finishedAt),
            'duration_ms' => $durationMs,
            'status'      => $status,
            'result'      => ($status === 'ok') ? $result : null,
            'error'       => $errArr,
            'context'     => $ctx,
            'monitoring'  => null,
        ];

        try {
            if ($status === 'ok' || $status === 'skipped') {
                $this->monitoring->onTaskRunOk($name, $record, $ctx);
            } else {
                $this->monitoring->onTaskRunFail(
                    $name,
                    $record,
                    new \RuntimeException((string) ($errArr['message'] ?? 'Task failed')),
                    $ctx
                );
            }
        } catch (\Throwable) {
            // Monitoring must never break scheduler.
        }

        $record['monitoring'] = [
            'implementation' => $this->monitoring instanceof NoopMonitoring ? 'noop' : \get_class($this->monitoring),
            'alert_invoked'  => !($this->monitoring instanceof NoopMonitoring),
        ];

        return $record;
    }

    /**
     * Decide whether a task is due or has a missed run at $now.
     *
     * Concurrency policy:
     * - allow_concurrent: schedule solely from last_run.
     * - non-overlapping: use last_started_at as in-progress anchor when fresh.
     *
     * Stale run detection: started_at older than max_runtime_sec (default 3600) is ignored.
     *
     * @param string             $name
     * @param \DateTimeInterface $now
     * @return bool
     */
    private function isDueOrMissed(string $name, \DateTimeInterface $now): bool
    {
        if (!isset($this->tasks[$name])) {
            return false;
        }

        $expr            = $this->getCronForTask($name);
        $lastRunTs       = $this->lastRun($name);
        $allowConcurrent = (bool) ($this->tasks[$name]['allow_concurrent'] ?? false);

        if ($allowConcurrent) {
            if ($lastRunTs === null) {
                return $expr->isDue($now);
            }

            $lastDt   = $this->clock->at('@' . $lastRunTs)->setTimezone($this->clock->tz());
            $nextSlot = $expr->getNextRunDate($lastDt, 0, false, $this->clock->tz()->getName());

            return $nextSlot->getTimestamp() <= $now->getTimestamp();
        }

        $lastStartedTs = $this->lastStartedAt($name);

        $maxRuntimeSec = 3600;
        $optBase       = $this->tasks[$name]['opt'] ?? [];
        if (\is_array($optBase) && \array_key_exists('max_runtime_sec', $optBase)) {
            $v = (int) $optBase['max_runtime_sec'];
            if ($v > 0) {
                $maxRuntimeSec = $v;
            }
        }

        // Already started in the same minute.
        if (
            $lastStartedTs !== null
            && $this->clock->formatMinuteKeyFromTimestamp($lastStartedTs) === $this->clock->formatMinuteKey($now)
        ) {
            return false;
        }

        // Already completed in the same minute.
        if (
            $lastRunTs !== null
            && $this->clock->formatMinuteKeyFromTimestamp($lastRunTs) === $this->clock->formatMinuteKey($now)
        ) {
            return false;
        }

        // First ever run.
        if ($lastRunTs === null && $lastStartedTs === null) {
            return $expr->isDue($now);
        }

        $anchorTs = $lastRunTs ?? 0;

        if ($lastStartedTs !== null) {
            $age = $now->getTimestamp() - $lastStartedTs;

            if ($maxRuntimeSec > 0 && $age < $maxRuntimeSec) {
                $anchorTs = \max($anchorTs, $lastStartedTs);
            }
        }

        if ($anchorTs <= 0) {
            return $expr->isDue($now);
        }

        $anchorDt = $this->clock->at('@' . $anchorTs)->setTimezone($this->clock->tz());
        $nextSlot = $expr->getNextRunDate($anchorDt, 0, false, $this->clock->tz()->getName());

        return $nextSlot->getTimestamp() <= $now->getTimestamp();
    }

    /**
     * Read the last_started_at timestamp for a task.
     *
     * @param string $name
     * @return int|null
     */
    private function lastStartedAt(string $name): ?int
    {
        $value = $this->store->read("last_started_at_{$name}");
        if ($value === null) {
            return null;
        }

        $ts = (int) $value;
        return $ts > 0 ? $ts : null;
    }

    /**
     * Stamp last_started_at to mark task as in-progress.
     *
     * @param string                  $name
     * @param \DateTimeInterface|null $now
     * @return int
     */
    private function stampStartedAt(string $name, ?\DateTimeInterface $now = null): int
    {
        $nowDt = $now ? $this->clock->fromInterface($now) : $this->clock->now();
        $ts    = $nowDt->getTimestamp();

        $this->store->write("last_started_at_{$name}", (string) $ts);
        return $ts;
    }

    /**
     * Clear last_started_at after task completion.
     *
     * Uses "0" sentinel for stores that do not support key deletion.
     *
     * @param string $name
     * @return void
     */
    private function clearStartedAt(string $name): void
    {
        $this->store->write("last_started_at_{$name}", '0');
    }

    /**
     * Read last_run timestamp for a task.
     *
     * @param string $name
     * @return int|null
     */
    private function lastRun(string $name): ?int
    {
        $value = $this->store->read("last_run_{$name}");
        return $value !== null ? (int) $value : null;
    }

    /**
     * Persist last_run timestamp after successful execution.
     *
     * @param string                  $name
     * @param CronExpression|null     $expr Unused; kept for signature compatibility.
     * @param \DateTimeInterface|null $now
     * @return int
     */
    private function markAsRun(string $name, ?CronExpression $expr = null, ?\DateTimeInterface $now = null): int
    {
        $nowDt = $now ? $this->clock->fromInterface($now) : $this->clock->now();
        $ts    = $nowDt->getTimestamp();

        $this->store->write("last_run_{$name}", (string) $ts);
        return $ts;
    }

    /**
     * Build and validate a CronExpression.
     *
     * @param string $expression
     * @param string $taskName
     * @return CronExpression
     * @throws \InvalidArgumentException When the expression is invalid.
     */
    private function buildCronExpression(string $expression, string $taskName): CronExpression
    {
        try {
            return new CronExpression($expression);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                "Invalid cron expression for task '{$taskName}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Return the cached CronExpression for a registered task.
     *
     * @param string $name
     * @return CronExpression
     */
    private function getCronForTask(string $name): CronExpression
    {
        $cron = $this->tasks[$name]['cron'] ?? null;
        if ($cron instanceof CronExpression) {
            return $cron;
        }

        $expression = (string) ($this->tasks[$name]['expression'] ?? '');
        $cron       = $this->buildCronExpression($expression, $name);

        $this->tasks[$name]['cron'] = $cron;
        return $cron;
    }

    /**
     * Execute task callback through the registered middleware pipeline.
     *
     * @param string      $name
     * @param callable    $cb
     * @param array<string,mixed> $ctx
     * @param TaskContext $taskContext
     * @return mixed
     */
    private function executeWithMiddlewares(string $name, callable $cb, array $ctx, TaskContext $taskContext): mixed
    {
        $runner = function () use ($cb, $taskContext) {
            return $this->invokeCallbackCompat($cb, $taskContext);
        };

        $middlewares = $this->tasks[$name]['middlewares'] ?? [];
        if (!\is_array($middlewares) || $middlewares === []) {
            return $runner();
        }

        for ($i = \count($middlewares) - 1; $i >= 0; $i--) {
            $mw = $middlewares[$i] ?? null;
            if (!$mw instanceof TaskMiddlewareInterface) {
                continue;
            }

            $next   = $runner;
            $runner = static function () use ($mw, $name, $ctx, $next) {
                return $mw->handle($name, $ctx, $next);
            };
        }

        return $runner();
    }

    /**
     * Invoke callback with backward-compatible signature support.
     *
     * Supports callable(TaskContext) and callable().
     *
     * @param callable    $cb
     * @param TaskContext $context
     * @return mixed
     */
    private function invokeCallbackCompat(callable $cb, TaskContext $context): mixed
    {
        try {
            if (\is_array($cb) && isset($cb[0], $cb[1])) {
                $ref = new \ReflectionMethod($cb[0], (string) $cb[1]);
                return ($ref->getNumberOfParameters() >= 1) ? $cb($context) : $cb();
            }

            if (\is_string($cb) && \str_contains($cb, '::')) {
                $ref = new \ReflectionMethod($cb);
                return ($ref->getNumberOfParameters() >= 1) ? $cb($context) : $cb();
            }

            $ref = new \ReflectionFunction(\Closure::fromCallable($cb));
            return ($ref->getNumberOfParameters() >= 1) ? $cb($context) : $cb();
        } catch (\Throwable) {
            return $cb($context);
        }
    }

    /**
     * Build final runtime options from base config and request context.
     *
     * Rules:
     * - Start from task base options (registered with add()).
     * - Apply overrides from context (GET params / queue passthrough).
     * - Context wins when value !== null and !== ''.
     * - Internal scheduler/queue keys are stripped.
     * - Scalars are coerced to base option types (bool/int/float).
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function buildRuntimeOptions(array $base, array $ctx): array
    {
        $overrides = $ctx;

        foreach (['task', 'mode', 'queue', 'queue_id', 'queue_index', 'queue_part', 'queue_state_path', 'qt', 'run_id', 'index', 'part'] as $k) {
            unset($overrides[$k]);
        }

        $overrides = $this->coerceOverridesByBaseTypes($base, $overrides);

        return TaskContext::mergeOpt($base, $overrides);
    }

    /**
     * Coerce override scalar values to match the types declared in $base.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function coerceOverridesByBaseTypes(array $base, array $overrides): array
    {
        foreach ($overrides as $k => $v) {
            if (!\array_key_exists($k, $base)) {
                continue;
            }

            $t = $base[$k];

            if (\is_bool($t)) {
                $overrides[$k] = $this->toBool($v);
            } elseif (\is_int($t)) {
                $overrides[$k] = (int) $v;
            } elseif (\is_float($t)) {
                $overrides[$k] = (float) $v;
            }
        }

        return $overrides;
    }

    /**
     * Parse a boolean from common GET/ENV forms.
     *
     * @param mixed $v
     * @return bool
     */
    private function toBool(mixed $v): bool
    {
        if (\is_bool($v)) {
            return $v;
        }

        if (\is_int($v)) {
            return $v !== 0;
        }

        if (\is_string($v)) {
            $s = \strtolower(\trim($v));
            if (\in_array($s, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($s, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        return (bool) $v;
    }
}
