<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use rafalmasiarek\DashboardKit\Module\ModuleRegistry;
use rafalmasiarek\DashboardKitScheduler\Http\JobHandler;
use rafalmasiarek\DashboardKitScheduler\Http\JsonResponder;
use rafalmasiarek\DashboardKitScheduler\Http\QueueManager;
use rafalmasiarek\DashboardKitScheduler\Http\RunHandler;
use rafalmasiarek\DashboardKitScheduler\Http\StatusHandler;
use rafalmasiarek\DashboardKitScheduler\Task\Builtin\CallableTaskFactory;
use rafalmasiarek\DashboardKitScheduler\Task\TaskRegistry;
use Slim\App;

/**
 * Wires the scheduler engine into a Dashboard application.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class SchedulerAddon
{
    /**
     * Register the scheduler addon.
     *
     * @param App                $app
     * @param ContainerInterface $container
     * @param array<string,mixed> $config    Optional configuration overrides.
     * @return void
     */
    public static function register(App $app, ContainerInterface $container, array $config = []): void
    {
        if (!\class_exists(\rafalmasiarek\DashboardKit\Dashboard::class)) {
            throw new \LogicException(
                static::class . ' is a dashboard-kit addon and requires rafalmasiarek/dashboard-kit. '
                . 'Run: composer require rafalmasiarek/dashboard-kit'
            );
        }

        $appConfig     = $container->has('app.config') ? (array) $container->get('app.config') : [];
        $schedulerCfg  = \array_merge((array) ($appConfig['scheduler'] ?? []), $config);

        $prefix          = \rtrim((string) ($schedulerCfg['prefix'] ?? ''), '/');
        $nativeEndpoint  = (bool) ($schedulerCfg['endpoint'] ?? true);
        $exposeRun       = (bool) ($schedulerCfg['expose_run'] ?? true);
        $token           = (string) ($schedulerCfg['token'] ?? \getenv('SCHEDULER_TOKEN') ?? '');
        $timezone        = (string) ($appConfig['app']['timezone'] ?? '');

        $rootDir = $container->has('app.root_dir')
            ? (string) $container->get('app.root_dir')
            : \getcwd();

        $storageDir = $rootDir . '/storage';

        $container->set(Scheduler::class, static function () use ($container, $storageDir, $appConfig, $timezone): Scheduler {
            $systemLogger = $container->has('logger.system')
                ? $container->get('logger.system')
                : null;

            $store = new PdoStateStore($container->get(\PDO::class), null, $systemLogger);
            $clock = new Clock($timezone);

            $sysMonitoring  = $systemLogger !== null ? new SystemLogMonitoring($systemLogger) : null;
            $userMonitoring = $container->has(MonitoringInterface::class)
                ? $container->get(MonitoringInterface::class)
                : null;

            if ($sysMonitoring !== null && $userMonitoring !== null) {
                $monitoring = new CompositeMonitoring([$sysMonitoring, $userMonitoring]);
            } else {
                $monitoring = $sysMonitoring ?? $userMonitoring;
            }

            $scheduler = new Scheduler($store, $storageDir, null, $monitoring, $clock);

            $registry = $container->get(ModuleRegistry::class);
            self::loadTasks($scheduler, $registry, $container, $clock, $systemLogger);
            self::loadSystemTasks($scheduler, $appConfig, $container, $clock, $systemLogger);

            $scheduler->add(
                '15 3 * * *',
                static function () use ($storageDir): void {
                    self::cleanupQueueFiles($storageDir . '/cron_queues');
                },
                'system.scheduler.cleanup_queue',
                [],
                false
            );

            return $scheduler;
        });

        $container->set(QueueManager::class, static fn() => new QueueManager($storageDir));

        if (!$nativeEndpoint) {
            return;
        }

        $group = $prefix !== '' ? $prefix : '';

        $app->group($group, function (\Slim\Routing\RouteCollectorProxy $g) use ($container, $token, $exposeRun, $timezone): void {
            // ------------------------------------------------------------------
            // GET /scheduler/status — public
            // ------------------------------------------------------------------
            $g->get('/scheduler/status', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($container, $timezone): ResponseInterface {
                $scheduler = $container->get(Scheduler::class);
                $clock     = new Clock($timezone);
                $handler   = new StatusHandler($scheduler, $clock);
                return $handler($request, $response);
            });

            if ($exposeRun) {
                // ------------------------------------------------------------------
                // GET /scheduler/run — authenticated (SCHEDULER_TOKEN or Bearer)
                // ------------------------------------------------------------------
                $g->get('/scheduler/run', function (
                    ServerRequestInterface $request,
                    ResponseInterface $response
                ) use ($container, $token, $timezone): ResponseInterface {
                    $provided = '';
                    $auth     = $request->getHeaderLine('Authorization');
                    if (\str_starts_with($auth, 'Bearer ')) {
                        $provided = \substr($auth, 7);
                    }
                    if ($provided === '') {
                        $provided = (string) ($request->getQueryParams()['token'] ?? '');
                    }

                    if ($token === '' || $provided === '' || !\hash_equals($token, $provided)) {
                        return JsonResponder::error($response, 'Unauthorized.', 401, 'CRON_UNAUTHORIZED');
                    }

                    $scheduler    = $container->get(Scheduler::class);
                    $queueManager = $container->get(QueueManager::class);
                    $clock        = new Clock($timezone);
                    $handler      = new RunHandler($scheduler, $clock, $queueManager);
                    return $handler($request, $response);
                });
            }

            // ------------------------------------------------------------------
            // GET /scheduler/job — internal queue execution (queue token)
            // ------------------------------------------------------------------
            $g->get('/scheduler/job', function (
                ServerRequestInterface $request,
                ResponseInterface $response
            ) use ($container, $timezone): ResponseInterface {
                $scheduler    = $container->get(Scheduler::class);
                $queueManager = $container->get(QueueManager::class);
                $clock        = new Clock($timezone);
                $handler      = new JobHandler($scheduler, $clock, $queueManager);
                return $handler($request, $response);
            });
        });

        if ($exposeRun && $token === '') {
            $warnLogger = $container->has('logger.system') ? $container->get('logger.system') : null;
            $warnLogger?->warning('scheduler.token_missing', [
                'endpoint' => ($prefix !== '' ? $prefix : '') . '/scheduler/run',
                'hint'     => 'Set SCHEDULER_TOKEN env var; the run endpoint is effectively disabled.',
            ]);
        }
    }

    /**
     * Register system-level tasks defined in app.config['cron'].
     *
     * Each task's global ID is: system.<task-id>
     *
     * Config format:
     *   'cron' => [
     *       'my-task' => [
     *           'cron'    => '0 3 * * *',
     *           'handler' => static function (): void { ... },
     *           'enabled' => true,  // optional, default true
     *       ],
     *   ],
     *
     * @param Scheduler            $scheduler
     * @param array<string,mixed>  $appConfig
     * @param ContainerInterface   $container
     * @param Clock                $clock
     * @param LoggerInterface|null $logger
     * @return void
     */
    private static function loadSystemTasks(
        Scheduler $scheduler,
        array $appConfig,
        ContainerInterface $container,
        Clock $clock,
        ?LoggerInterface $logger = null
    ): void {
        $tasks = (array) ($appConfig['cron'] ?? []);

        if ($tasks === []) {
            return;
        }

        $factory = new CallableTaskFactory();
        $loaded  = 0;

        foreach ($tasks as $localId => $def) {
            $localId = (string) $localId;
            $cron    = (string) ($def['cron'] ?? '');
            $handler = $def['handler'] ?? null;
            $enabled = (bool) ($def['enabled'] ?? true);

            if (!$enabled) {
                continue;
            }

            if ($localId === '' || $cron === '' || $handler === null) {
                $logger?->warning('task.config_error', [
                    'source' => 'system',
                    'task'   => $localId,
                    'reason' => 'missing cron or handler',
                ]);
                continue;
            }

            $taskId  = 'system.' . $localId;
            $options = (array) ($def['options'] ?? []);
            $opt     = \array_merge(['handler' => $handler], $options);

            try {
                $cb = $factory->build($taskId, $container, $clock, $opt);
            } catch (\Throwable $e) {
                $logger?->error('task.build_error', [
                    'task'  => $taskId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $scheduler->add($cron, $cb, $taskId, $opt, false);
            $loaded++;
        }

        $logger?->info('scheduler.system_tasks_loaded', ['count' => $loaded]);
    }

    /**
     * Discover and register tasks from all modules that define a 'schedule' key.
     *
     * Each task's global ID is: <module-slug>.<task-id>
     *
     * @param Scheduler            $scheduler
     * @param ModuleRegistry       $registry
     * @param ContainerInterface   $container
     * @param Clock                $clock
     * @param LoggerInterface|null $logger   Optional logger for config errors and load summary.
     * @return void
     */
    private static function loadTasks(
        Scheduler $scheduler,
        ModuleRegistry $registry,
        ContainerInterface $container,
        Clock $clock,
        ?LoggerInterface $logger = null
    ): void {
        $taskRegistry = self::buildTaskRegistry();
        $loaded       = 0;

        foreach ($registry->scheduledModules() as $module) {
            $slug  = (string) ($module['slug'] ?? '');
            $tasks = (array) ($module['schedule'] ?? []);

            foreach ($tasks as $def) {
                $localId       = (string) ($def['id'] ?? '');
                $cron          = (string) ($def['cron'] ?? '');
                $action        = (string) ($def['action'] ?? '');
                $enabled       = (bool) ($def['enabled'] ?? true);
                $opt           = (array) ($def['options'] ?? []);
                $allowParallel = (bool) ($def['allow_parallel'] ?? false);

                if (!$enabled) {
                    continue;
                }

                if ($localId === '' || $cron === '' || $action === '') {
                    $logger?->warning('task.config_error', [
                        'module' => $slug,
                        'reason' => 'missing id, cron, or action',
                    ]);
                    continue;
                }

                $taskId  = $slug !== '' ? "{$slug}.{$localId}" : $localId;
                $factory = $taskRegistry->get($action);

                if ($factory === null) {
                    $logger?->warning('task.config_error', [
                        'module' => $slug,
                        'task'   => $taskId,
                        'reason' => "unknown action '{$action}'",
                    ]);
                    continue;
                }

                try {
                    $cb = $factory->build($taskId, $container, $clock, $opt);
                } catch (\Throwable $e) {
                    $logger?->error('task.build_error', [
                        'task'  => $taskId,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $scheduler->add($cron, $cb, $taskId, $opt, $allowParallel);
                $loaded++;
            }
        }

        $logger?->info('scheduler.tasks_loaded', ['count' => $loaded]);
    }

    /**
     * Build the built-in task factory registry.
     *
     * @return TaskRegistry
     */
    private static function buildTaskRegistry(): TaskRegistry
    {
        $registry = new TaskRegistry();
        $registry->register('callable', new CallableTaskFactory());
        return $registry;
    }

    /**
     * Remove queue JSON files older than 24 hours from the queue directory.
     *
     * @param string $dir
     * @return void
     */
    private static function cleanupQueueFiles(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $cutoff = \time() - 86400;

        foreach (\glob($dir . '/*.json') ?: [] as $file) {
            if (\is_file($file) && \filemtime($file) < $cutoff) {
                @\unlink($file);
                $lock = $file . '.lock';
                if (\is_file($lock)) {
                    @\unlink($lock);
                }
            }
        }
    }
}
