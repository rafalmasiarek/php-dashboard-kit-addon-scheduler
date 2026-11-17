## Module schedule format

Add a `schedule` key to any module's `module.php`:

```php
return [
    'title'    => 'Notes',
    'icon'     => '📝',
    'schedule' => [
        [
            'id'      => 'cleanup',
            'cron'    => '0 3 * * *',
            'action'  => 'callable',
            'options' => [
                'handler' => function (\rafalmasiarek\DashboardKitScheduler\TaskContext $ctx): void {
                    // runs at 03:00 every day
                },
            ],
        ],
    ],
    'render' => function ($req, $res, $args, $twig, $db, $container) { ... },
];
```

The global task ID will be `notes.cleanup`. Tasks appear in `/scheduler/status`.

### Task definition keys

| Key | Required | Description |
|-----|----------|-------------|
| `id` | yes | Local task ID (prefixed with module slug for the global ID) |
| `cron` | yes | Cron expression (5-field, e.g. `'*/5 * * * *'`) |
| `action` | yes | Factory action name. Built-in: `'callable'` |
| `options` | no | Action-specific options. For `callable`: `handler` (PHP callable or `'Class@method'`) |
| `enabled` | no | `false` disables the task without removing it (default: `true`) |
| `allow_parallel` | no | Allow concurrent runs (default: `false`) |

## Built-in cleanup task

`__scheduler.cleanup` runs daily at 03:15 and removes queue files older than 24 hours from `storage/cron_queues/`. It is registered automatically — no configuration needed.

## Monitoring

The plugin provides `MonitoringInterface` and `NoopMonitoring` (default). To wire a custom implementation:

```php
use rafalmasiarek\DashboardKitScheduler\MonitoringInterface;

// Set before $dashboard->run() — resolved lazily when Scheduler is first used
$dashboard->getContainer()->set(MonitoringInterface::class, static fn() => new MyMonitoring([
    'my-module.my-task' => ['url' => 'https://hc-ping.com/your-uuid'],
]));
```

Or read from `app.config` (config lives alongside other scheduler settings):

```php
$container = $dashboard->getContainer();

$container->set(MonitoringInterface::class, static function () use ($container) {
    $cfg = (array) ($container->get('app.config')['scheduler']['monitoring']['healthchecks'] ?? []);
    return new MyMonitoring($cfg);
});
```

`MyMonitoring` must implement `rafalmasiarek\DashboardKitScheduler\MonitoringInterface`:

```php
interface MonitoringInterface
{
    public function onTaskRunStart(string $taskId, array $context = []): void;
    public function onTaskRunOk(string $taskId, array $record, array $ctx): void;
    public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $ctx): void;
}
```

All methods must swallow their own exceptions — monitoring must never break scheduling.

## Disabling native endpoints

When `endpoint: false`, only `Scheduler::class` and `QueueManager::class` are registered in the container. Use this when building custom routes with your own auth (e.g. via `dashboard-kit-addon-api` token scopes).

```php
SchedulerAddon::register($dashboard->getApp(), $dashboard->getContainer(), [
    'endpoint' => false,
]);
```
