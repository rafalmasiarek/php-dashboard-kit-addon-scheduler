# dashboard-kit-addon-scheduler
[![Packagist Version](https://img.shields.io/packagist/v/rafalmasiarek/dashboard-kit-addon-scheduler)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-scheduler)
[![Total Downloads](https://img.shields.io/packagist/dt/rafalmasiarek/dashboard-kit-addon-scheduler)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-scheduler)
[![PHP](https://img.shields.io/packagist/php-v/rafalmasiarek/dashboard-kit-addon-scheduler)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-scheduler)
[![License](https://img.shields.io/badge/license-BUSL--1.1-red)](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/LICENSE)

Cron-expression task scheduler addon for [rafalmasiarek/dashboard-kit](https://github.com/rafalmasiarek/php-dashboard-kit).

Auto-discovers tasks from module `schedule` definitions. Runs tasks via HTTP endpoints with a queue+redirect mechanism to avoid PHP execution timeouts on long-running jobs.




## Requirements

- PHP 8.2+
- `rafalmasiarek/dashboard-kit: *`

## Installation

```bash
composer require rafalmasiarek/dashboard-kit-addon-scheduler
```

## Quick start

```php
use rafalmasiarek\DashboardKitScheduler\SchedulerAddon;

$dashboard = Dashboard::create(__DIR__ . '/../', [
    'scheduler' => [
        'token' => $_ENV['SCHEDULER_TOKEN'],
    ],
]);

SchedulerAddon::register($dashboard->getApp(), $dashboard->getContainer());

$dashboard->run();
```

## Configuration

| Key | Default | Description |
|-----|---------|-------------|
| `endpoint` | `true` | Register native HTTP endpoints (`/scheduler/status`, `/scheduler/run`, `/scheduler/job`) |
| `prefix` | `''` | URL prefix prepended to all endpoints (e.g. `'/v1'`) |
| `token` | `SCHEDULER_TOKEN` env | Bearer token required on `GET /scheduler/run` |

Config can be passed as the third argument to `register()` or via `app.config['scheduler']` in `Dashboard::create()`.

## HTTP endpoints

| Endpoint | Auth | Description |
|----------|------|-------------|
| `GET /scheduler/status` | none | Lists all tasks with `last_run_at` and `is_due` |
| `GET /scheduler/run` | Bearer token | Runs due tasks; queues long-running ones and redirects |
| `GET /scheduler/job` | queue token | Executes one step of a queued task (internal) |

Trigger from a system cron:

```bash
* * * * * curl -s -H "Authorization: Bearer $TOKEN" https://example.com/scheduler/run
```


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


## Index

### Classes

- [`CompositeMonitoring`](#compositemonitoring) — `rafalmasiarek\DashboardKitScheduler`
- [`TaskLockedException`](#tasklockedexception) — `rafalmasiarek\DashboardKitScheduler\Exception`
- [`FileStateStore`](#filestatestore) — `rafalmasiarek\DashboardKitScheduler`
- [`JobHandler`](#jobhandler) — `rafalmasiarek\DashboardKitScheduler\Http`
- [`JsonResponder`](#jsonresponder) — `rafalmasiarek\DashboardKitScheduler\Http`
- [`QueueManager`](#queuemanager) — `rafalmasiarek\DashboardKitScheduler\Http`
- [`RunHandler`](#runhandler) — `rafalmasiarek\DashboardKitScheduler\Http`
- [`StatusHandler`](#statushandler) — `rafalmasiarek\DashboardKitScheduler\Http`
- [`ProcessFileLock`](#processfilelock) — `rafalmasiarek\DashboardKitScheduler\Lock`
- [`FileLockMiddleware`](#filelockmiddleware) — `rafalmasiarek\DashboardKitScheduler\Middleware`
- [`NoopMonitoring`](#noopmonitoring) — `rafalmasiarek\DashboardKitScheduler`
- [`AbstractOutputPolicy`](#abstractoutputpolicy) — `rafalmasiarek\DashboardKitScheduler\Output`
- [`CronOutputBuilder`](#cronoutputbuilder) — `rafalmasiarek\DashboardKitScheduler\Output`
- [`DefaultOutputPolicy`](#defaultoutputpolicy) — `rafalmasiarek\DashboardKitScheduler\Output`
- [`OutputPolicyResolver`](#outputpolicyresolver) — `rafalmasiarek\DashboardKitScheduler\Output`
- [`PdoStateStore`](#pdostatestore) — `rafalmasiarek\DashboardKitScheduler`
- [`QueuePayload`](#queuepayload) — `rafalmasiarek\DashboardKitScheduler`
- [`Scheduler`](#scheduler) — `rafalmasiarek\DashboardKitScheduler`
- [`SchedulerAddon`](#scheduleraddon) — `rafalmasiarek\DashboardKitScheduler`
- [`SchedulerSchemaProvider`](#schedulerschemaprovider) — `rafalmasiarek\DashboardKitScheduler\Schema`
- [`SystemLogMonitoring`](#systemlogmonitoring) — `rafalmasiarek\DashboardKitScheduler`
- [`CallableTaskFactory`](#callabletaskfactory) — `rafalmasiarek\DashboardKitScheduler\Task\Builtin`
- [`TaskRegistry`](#taskregistry) — `rafalmasiarek\DashboardKitScheduler\Task`
- [`TaskContext`](#taskcontext) — `rafalmasiarek\DashboardKitScheduler`

### Interfaces

- [`MonitoringInterface`](#monitoringinterface) — `rafalmasiarek\DashboardKitScheduler`
- [`OutputPolicyInterface`](#outputpolicyinterface) — `rafalmasiarek\DashboardKitScheduler\Output`
- [`SchedulerStateStore`](#schedulerstatestore) — `rafalmasiarek\DashboardKitScheduler`
- [`TaskFactoryInterface`](#taskfactoryinterface) — `rafalmasiarek\DashboardKitScheduler\Task`
- [`TaskMiddlewareInterface`](#taskmiddlewareinterface) — `rafalmasiarek\DashboardKitScheduler`

## `CompositeMonitoring`

*final class* — `rafalmasiarek\DashboardKitScheduler\CompositeMonitoring` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/CompositeMonitoring.php#L17)

Delegates monitoring events to multiple MonitoringInterface implementations. Used internally by SchedulerAddon to chain SystemLogMonitoring with any user-supplied MonitoringInterface binding, so both receive every lifecycle event. Each delegate's exceptions are suppressed individually so one failing monitor cannot prevent others from being called.

implements [`MonitoringInterface`](#monitoringinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#compositemonitoring-__construct) | public | — | — |
| [`onTaskRunStart()`](#compositemonitoring-ontaskrunstart) | public | `void` | Called right before running a task. |
| [`onTaskRunOk()`](#compositemonitoring-ontaskrunok) | public | `void` | Called after a successful task run. |
| [`onTaskRunFail()`](#compositemonitoring-ontaskrunfail) | public | `void` | Called after a failed task run. |

<details>
<summary><code>__construct()</code></summary>

#### `CompositeMonitoring __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/CompositeMonitoring.php#L22)

```php
public function __construct(array $monitors)
```

| Param | Type | Description |
|---|---|---|
| `$monitors` | `MonitoringInterface[]` | Ordered list of monitors to invoke. |

</details>

<details>
<summary><code>onTaskRunStart()</code></summary>

#### `CompositeMonitoring onTaskRunStart()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/CompositeMonitoring.php#L29)

```php
public function onTaskRunStart(string $taskId, array $context): void
```

Called right before running a task.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$context` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>onTaskRunOk()</code></summary>

#### `CompositeMonitoring onTaskRunOk()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/CompositeMonitoring.php#L42)

```php
public function onTaskRunOk(string $taskId, array $record, array $ctx): void
```

Called after a successful task run.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$record` | `array<string, mixed>` | Full task run record from Scheduler::runTask(). |
| `$ctx` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>onTaskRunFail()</code></summary>

#### `CompositeMonitoring onTaskRunFail()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/CompositeMonitoring.php#L55)

```php
public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $ctx): void
```

Called after a failed task run.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$record` | `array<string, mixed>` | Full task run record from Scheduler::runTask(). |
| `$e` | `\Throwable` | Exception thrown by the task. |
| `$ctx` | `array<string, mixed>` | — |

</details>

## `TaskLockedException`

*final class* — `rafalmasiarek\DashboardKitScheduler\Exception\TaskLockedException` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Exception/TaskLockedException.php#L12)

Thrown when a task's file lock cannot be acquired.

extends `\RuntimeException`

**Package:** `rafalmasiarek\DashboardKitScheduler`

## `FileStateStore`

*final class* — `rafalmasiarek\DashboardKitScheduler\FileStateStore` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/FileStateStore.php#L14)

File-based implementation of SchedulerStateStore. Each key is stored as a separate file in the given directory.

implements [`SchedulerStateStore`](#schedulerstatestore)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$dir` | `string` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#filestatestore-__construct) | public | — | — |
| [`read()`](#filestatestore-read) | public | `string\|null` | Read a value by key. |
| [`write()`](#filestatestore-write) | public | `void` | Write a value by key. |
| [`delete()`](#filestatestore-delete) | public | `void` | Delete a value by key. |

<details>
<summary><code>__construct()</code></summary>

#### `FileStateStore __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/FileStateStore.php#L24)

```php
public function __construct(string $dir)
```

| Param | Type | Description |
|---|---|---|
| `$dir` | `string` | Directory path for state files (created automatically if missing). |

</details>

<details>
<summary><code>read()</code></summary>

#### `FileStateStore read()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/FileStateStore.php#L35)

```php
public function read(string $key): ?string
```

Read a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |

**Returns:** `string\|null`

</details>

<details>
<summary><code>write()</code></summary>

#### `FileStateStore write()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/FileStateStore.php#L47)

```php
public function write(string $key, string $value): void
```

Write a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$value` | `string` | — |

</details>

<details>
<summary><code>delete()</code></summary>

#### `FileStateStore delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/FileStateStore.php#L56)

```php
public function delete(string $key): void
```

Delete a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |

</details>

## `JobHandler`

*final class* — `rafalmasiarek\DashboardKitScheduler\Http\JobHandler` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/JobHandler.php#L24)

GET /cron/job — internal endpoint (queue token required). Executes one task step from a queue created by RunHandler. After execution either: - 303-redirects to the next part of the same task (multi-part), - 303-redirects to the next pending task in the queue, - returns 200 with "Cron queue completed." when all tasks are done.

**Depends on:** [`Scheduler`](#scheduler), `ClockInterface`, [`QueueManager`](#queuemanager)

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$scheduler` | [`Scheduler`](#scheduler) | private | — |
| `$clock` | `ClockInterface` | private | — |
| `$queueManager` | [`QueueManager`](#queuemanager) | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#jobhandler-__construct) | public | — | — |
| [`__invoke()`](#jobhandler-__invoke) | public | `ResponseInterface` | Handle a single job step from a queue. |

<details>
<summary><code>__construct()</code></summary>

#### `JobHandler __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/JobHandler.php#L46)

```php
public function __construct(Scheduler $scheduler, ClockInterface $clock, QueueManager $queueManager)
```

| Param | Type | Description |
|---|---|---|
| `$scheduler` | [`Scheduler`](#scheduler) | — |
| `$clock` | `ClockInterface` | — |
| `$queueManager` | [`QueueManager`](#queuemanager) | — |

</details>

<details>
<summary><code>__invoke()</code></summary>

#### `JobHandler __invoke()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/JobHandler.php#L60)

```php
public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
```

Handle a single job step from a queue.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$response` | `ResponseInterface` | — |

**Returns:** `ResponseInterface`

</details>

## `JsonResponder`

*final class* — `rafalmasiarek\DashboardKitScheduler\Http\JsonResponder` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/JsonResponder.php#L17)

Minimal JSON response builder for scheduler HTTP endpoints. Wraps responses in the same envelope as api.masiarek.pl's JsonHandler so that the output is structurally compatible when migrating.

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`ok()`](#jsonresponder-ok) | public static | `ResponseInterface` | Write a successful JSON response. |
| [`error()`](#jsonresponder-error) | public static | `ResponseInterface` | Write an error JSON response. |

<details>
<summary><code>ok()</code></summary>

#### `JsonResponder ok()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/JsonResponder.php#L28)

```php
public static function ok(ResponseInterface $response, string $message, array $data, int $status): ResponseInterface
```

Write a successful JSON response.

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$message` | `string` | — |
| `$data` | `array<string, mixed>` | — |
| `$status` | `int` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>error()</code></summary>

#### `JsonResponder error()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/JsonResponder.php#L55)

```php
public static function error(ResponseInterface $response, string $message, int $status, ?string $error): ResponseInterface
```

Write an error JSON response.

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$message` | `string` | — |
| `$status` | `int` | — |
| `$error` | `string\|null` | Machine-readable error code. |

**Returns:** `ResponseInterface`

</details>

## `QueueManager`

*final class* — `rafalmasiarek\DashboardKitScheduler\Http\QueueManager` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L18)

Manages cron queue files used by the queue+redirect anti-timeout mechanism. Each queue is a JSON file in the cron_queues directory. All mutations are serialized via an advisory flock on a "<id>.json.lock" file, matching the convention used by QueuePayload.

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$dir` | `string` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#queuemanager-__construct) | public | — | — |
| [`dir()`](#queuemanager-dir) | public | `string` | Return the queue directory path. |
| [`path()`](#queuemanager-path) | public | `string` | Return the absolute path for a queue JSON file. |
| [`create()`](#queuemanager-create) | public | `array<string, mixed>` | Create and persist a new queue file. |
| [`load()`](#queuemanager-load) | public | `array<string, mixed>\|null` | Load a queue file. |
| [`save()`](#queuemanager-save) | public | `void` | Persist a queue file. |
| [`validateToken()`](#queuemanager-validatetoken) | public | `array<string, mixed>\|null` | Validate a queue token and return the queue if valid. |
| [`withLock()`](#queuemanager-withlock) | public | `T` | Execute callback while holding an exclusive lock on the queue file. |
| [`filterPassthrough()`](#queuemanager-filterpassthrough) | public static | `array<string, string>` | Remove internal queue/routing params from query params; normalize values to strings. |
| [`buildPassthroughQs()`](#queuemanager-buildpassthroughqs) | public static | `string` | Build a "&"-prefixed query string from pass-through params. |
| [`exportToServer()`](#queuemanager-exporttoserver) | public static | `void` | Export pass-through params into $_SERVER as CRON_JOB_* variables. |

<details>
<summary><code>__construct()</code></summary>

#### `QueueManager __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L28)

```php
public function __construct(string $storageDir)
```

| Param | Type | Description |
|---|---|---|
| `$storageDir` | `string` | Base storage directory (e.g. "<root>/storage"). |

</details>

<details>
<summary><code>dir()</code></summary>

#### `QueueManager dir()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L42)

```php
public function dir(): string
```

Return the queue directory path.

**Returns:** `string`

</details>

<details>
<summary><code>path()</code></summary>

#### `QueueManager path()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L53)

```php
public function path(string $id): string
```

Return the absolute path for a queue JSON file.

| Param | Type | Description |
|---|---|---|
| `$id` | `string` | — |

**Returns:** `string`

</details>

<details>
<summary><code>create()</code></summary>

#### `QueueManager create()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L69)

```php
public function create(string $id, string $secret, array $tasks, array $passthroughQuery, ClockInterface $clock, array $runIdsByTask): array
```

Create and persist a new queue file.

| Param | Type | Description |
|---|---|---|
| `$id` | `string` | — |
| `$secret` | `string` | — |
| `$tasks` | `string[]` | — |
| `$passthroughQuery` | `array<string, string>` | — |
| `$clock` | `ClockInterface` | — |
| `$runIdsByTask` | `array<string, string>` | Optional run_id per task name. |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>load()</code></summary>

#### `QueueManager load()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L115)

```php
public function load(string $id): ?array
```

Load a queue file.

| Param | Type | Description |
|---|---|---|
| `$id` | `string` | — |

**Returns:** `array<string, mixed>\|null`

</details>

<details>
<summary><code>save()</code></summary>

#### `QueueManager save()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L139)

```php
public function save(array $queue): void
```

Persist a queue file.

| Param | Type | Description |
|---|---|---|
| `$queue` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>validateToken()</code></summary>

#### `QueueManager validateToken()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L161)

```php
public function validateToken(string $queueId, string $token): ?array
```

Validate a queue token and return the queue if valid.

| Param | Type | Description |
|---|---|---|
| `$queueId` | `string` | — |
| `$token` | `string` | — |

**Returns:** `array<string, mixed>\|null`

</details>

<details>
<summary><code>withLock()</code></summary>

#### `QueueManager withLock()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L188)

```php
public function withLock(string $id, callable $fn): 
```

Execute callback while holding an exclusive lock on the queue file.

| Param | Type | Description |
|---|---|---|
| `$id` | `string` | Queue ID. |
| `$fn` | `callable(): T` | — |

**Returns:** `T`

</details>

<details>
<summary><code>filterPassthrough()</code></summary>

#### `QueueManager filterPassthrough()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L222)

```php
public static function filterPassthrough(array $q): array
```

Remove internal queue/routing params from query params; normalize values to strings.

| Param | Type | Description |
|---|---|---|
| `$q` | `array<string, mixed>` | — |

**Returns:** `array<string, string>`

</details>

<details>
<summary><code>buildPassthroughQs()</code></summary>

#### `QueueManager buildPassthroughQs()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L258)

```php
public static function buildPassthroughQs(array $passthrough): string
```

Build a "&"-prefixed query string from pass-through params.

| Param | Type | Description |
|---|---|---|
| `$passthrough` | `array<string, string>` | — |

**Returns:** `string`

</details>

<details>
<summary><code>exportToServer()</code></summary>

#### `QueueManager exportToServer()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/QueueManager.php#L274)

```php
public static function exportToServer(array $passthrough): void
```

Export pass-through params into $_SERVER as CRON_JOB_* variables.

| Param | Type | Description |
|---|---|---|
| `$passthrough` | `array<string, string>` | — |

</details>

## `RunHandler`

*final class* — `rafalmasiarek\DashboardKitScheduler\Http\RunHandler` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/RunHandler.php#L25)

GET /cron — authenticated endpoint (SCHEDULER_TOKEN or Bearer). Without ?task=: discovers due tasks, creates a queue and 303-redirects to /cron/job. With ?task=<id>: runs a single task directly; if the task signals multi-part continuation (next_part != null in result) or queue-init mode (queue_init=true), creates a queue and 303-redirects to /cron/job. Pass-through: all query params except queue/qt/index/part are forwarded to all /cron/job calls.

**Depends on:** [`Scheduler`](#scheduler), `ClockInterface`, [`QueueManager`](#queuemanager)

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$scheduler` | [`Scheduler`](#scheduler) | private | — |
| `$clock` | `ClockInterface` | private | — |
| `$queueManager` | [`QueueManager`](#queuemanager) | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#runhandler-__construct) | public | — | — |
| [`__invoke()`](#runhandler-__invoke) | public | `ResponseInterface` | Handle the run request. |
| [`runSingleTask()`](#runhandler-runsingletask) | private | `ResponseInterface` | Run a single named task, handling queue-init and continuation modes. |
| [`createQueue()`](#runhandler-createqueue) | private | `ResponseInterface` | Create a queue for a single task and redirect to the first job. |
| [`isQueueInit()`](#runhandler-isqueueinit) | private | `bool` | Detect queue-init result: task returns ['queue_init' => true, 'total_parts' => N>=2]. |
| [`jobPath()`](#runhandler-jobpath) | private | `string` | Derive the /cron/job URL path from the current request. |
| [`qBool()`](#runhandler-qbool) | private | `bool\|null` | Read a boolean-ish query parameter. |

<details>
<summary><code>__construct()</code></summary>

#### `RunHandler __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/RunHandler.php#L47)

```php
public function __construct(Scheduler $scheduler, ClockInterface $clock, QueueManager $queueManager)
```

| Param | Type | Description |
|---|---|---|
| `$scheduler` | [`Scheduler`](#scheduler) | — |
| `$clock` | `ClockInterface` | — |
| `$queueManager` | [`QueueManager`](#queuemanager) | — |

</details>

<details>
<summary><code>__invoke()</code></summary>

#### `RunHandler __invoke()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/RunHandler.php#L61)

```php
public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
```

Handle the run request.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$response` | `ResponseInterface` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>runSingleTask()</code></summary>

#### `RunHandler runSingleTask()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/RunHandler.php#L137)

```php
private function runSingleTask(ServerRequestInterface $request, ResponseInterface $response, string $taskId, bool $force, array $passthrough, string $passQs, array $options): ResponseInterface
```

Run a single named task, handling queue-init and continuation modes.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$response` | `ResponseInterface` | — |
| `$taskId` | `string` | — |
| `$force` | `bool` | — |
| `$passthrough` | `array<string, string>` | — |
| `$passQs` | `string` | — |
| `$options` | `array<string, mixed>` | Output options (e.g. ['verbose' => true]). |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>createQueue()</code></summary>

#### `RunHandler createQueue()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/RunHandler.php#L226)

```php
private function createQueue(ServerRequestInterface $request, ResponseInterface $response, string $taskId, string $runId, array $passthrough, string $passQs, int $firstPart, array $initialRecord, string $message, array $options): ResponseInterface
```

Create a queue for a single task and redirect to the first job.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$response` | `ResponseInterface` | — |
| `$taskId` | `string` | — |
| `$runId` | `string` | — |
| `$passthrough` | `array<string, string>` | — |
| `$passQs` | `string` | — |
| `$firstPart` | `int` | — |
| `$initialRecord` | `array<string, mixed>` | — |
| `$message` | `string` | — |
| `$options` | `array<string, mixed>` | Output options (e.g. ['verbose' => true]). |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>isQueueInit()</code></summary>

#### `RunHandler isQueueInit()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/RunHandler.php#L281)

```php
private function isQueueInit(?array $result): bool
```

Detect queue-init result: task returns ['queue_init' => true, 'total_parts' => N>=2].

| Param | Type | Description |
|---|---|---|
| `$result` | `array<string, mixed>\|null` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>jobPath()</code></summary>

#### `RunHandler jobPath()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/RunHandler.php#L298)

```php
private function jobPath(ServerRequestInterface $request): string
```

Derive the /cron/job URL path from the current request.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |

**Returns:** `string`

</details>

<details>
<summary><code>qBool()</code></summary>

#### `RunHandler qBool()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/RunHandler.php#L318)

```php
private function qBool(array $q, string $key): ?bool
```

Read a boolean-ish query parameter.

| Param | Type | Description |
|---|---|---|
| `$q` | `array<string, mixed>` | — |
| `$key` | `string` | — |

**Returns:** `bool\|null`

</details>

## `StatusHandler`

*final class* — `rafalmasiarek\DashboardKitScheduler\Http\StatusHandler` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/StatusHandler.php#L19)

GET /cron/status — public endpoint. Lists all registered tasks with their cron expression, last_run_at and is_due flag.

**Depends on:** [`Scheduler`](#scheduler), `ClockInterface`

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$scheduler` | [`Scheduler`](#scheduler) | private | — |
| `$clock` | `ClockInterface` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#statushandler-__construct) | public | — | — |
| [`__invoke()`](#statushandler-__invoke) | public | `ResponseInterface` | Handle the status request. |

<details>
<summary><code>__construct()</code></summary>

#### `StatusHandler __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/StatusHandler.php#L35)

```php
public function __construct(Scheduler $scheduler, ClockInterface $clock)
```

| Param | Type | Description |
|---|---|---|
| `$scheduler` | [`Scheduler`](#scheduler) | — |
| `$clock` | `ClockInterface` | — |

</details>

<details>
<summary><code>__invoke()</code></summary>

#### `StatusHandler __invoke()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Http/StatusHandler.php#L48)

```php
public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
```

Handle the status request.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$response` | `ResponseInterface` | — |

**Returns:** `ResponseInterface`

</details>

## `ProcessFileLock`

*final class* — `rafalmasiarek\DashboardKitScheduler\Lock\ProcessFileLock` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Lock/ProcessFileLock.php#L21)

Single-file process lock using flock(LOCK_EX|LOCK_NB). Writes JSON metadata (pid + started_at) into the lock file for diagnostics. Registers a shutdown handler and optional PCNTL signal handlers to release the lock on normal exit, SIGTERM, SIGINT and SIGHUP. SIGKILL (kill -9) cannot be handled; the lock file may remain on disk in that case. The advisory lock itself is released by the OS when the process dies.

**Depends on:** `ClockInterface`

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$lockPath` | `string` | private | — |
| `$clock` | `ClockInterface\|null` | private | — |
| `$fh` | `resource\|null` | private | — |
| `$locked` | `bool` | private | — |
| `$handlersInstalled` | `bool` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#processfilelock-__construct) | public | — | — |
| [`acquire()`](#processfilelock-acquire) | public | `bool` | Try to acquire the lock (non-blocking). |
| [`release()`](#processfilelock-release) | public | `void` | Release the lock. |
| [`readMeta()`](#processfilelock-readmeta) | public | `array<string, mixed>\|null` | Read lock metadata written at acquire time (best-effort). |
| [`writeMetaToLockFile()`](#processfilelock-writemetatolockfile) | private | `void` | Write PID and start time into the lock file for diagnostics. |
| [`installHandlers()`](#processfilelock-installhandlers) | private | `void` | Register shutdown and optional PCNTL signal handlers. |

<details>
<summary><code>__construct()</code></summary>

#### `ProcessFileLock __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Lock/ProcessFileLock.php#L52)

```php
public function __construct(string $lockPath, ?ClockInterface $clock)
```

| Param | Type | Description |
|---|---|---|
| `$lockPath` | `string` | Absolute path to the lock file. |
| `$clock` | `ClockInterface\|null` | Optional clock for lock metadata timestamps. |

</details>

<details>
<summary><code>acquire()</code></summary>

#### `ProcessFileLock acquire()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Lock/ProcessFileLock.php#L63)

```php
public function acquire(): bool
```

Try to acquire the lock (non-blocking).

**Returns:** `bool` — True when the lock was acquired.

</details>

<details>
<summary><code>release()</code></summary>

#### `ProcessFileLock release()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Lock/ProcessFileLock.php#L94)

```php
public function release(): void
```

Release the lock.

</details>

<details>
<summary><code>readMeta()</code></summary>

#### `ProcessFileLock readMeta()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Lock/ProcessFileLock.php#L118)

```php
public function readMeta(): ?array
```

Read lock metadata written at acquire time (best-effort).

**Returns:** `array<string, mixed>\|null`

</details>

<details>
<summary><code>writeMetaToLockFile()</code></summary>

#### `ProcessFileLock writeMetaToLockFile()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Lock/ProcessFileLock.php#L134)

```php
private function writeMetaToLockFile(): void
```

Write PID and start time into the lock file for diagnostics.

</details>

<details>
<summary><code>installHandlers()</code></summary>

#### `ProcessFileLock installHandlers()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Lock/ProcessFileLock.php#L161)

```php
private function installHandlers(): void
```

Register shutdown and optional PCNTL signal handlers.

</details>

## `FileLockMiddleware`

*final class* — `rafalmasiarek\DashboardKitScheduler\Middleware\FileLockMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Middleware/FileLockMiddleware.php#L22)

Per-task file-lock middleware preventing concurrent execution. Lock file path: <locksDir>/<sanitized-task-name>.lock Guarantees lock release on normal exit and SIGTERM/SIGINT/SIGHUP when the pcntl extension is available. SIGKILL cannot be handled.

implements [`TaskMiddlewareInterface`](#taskmiddlewareinterface)

**Depends on:** `ClockInterface`

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$locksDir` | `string` | private | — |
| `$clock` | `ClockInterface\|null` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#filelockmiddleware-__construct) | public | — | — |
| [`handle()`](#filelockmiddleware-handle) | public | `mixed` | Handle task execution step. |
| [`sanitizeTaskName()`](#filelockmiddleware-sanitizetaskname) | private | `string` | Sanitize task name to a safe filename component. |

<details>
<summary><code>__construct()</code></summary>

#### `FileLockMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Middleware/FileLockMiddleware.php#L39)

```php
public function __construct(string $locksDir, ?ClockInterface $clock)
```

| Param | Type | Description |
|---|---|---|
| `$locksDir` | `string` | Directory for lock files (absolute path). |
| `$clock` | `ClockInterface\|null` | Optional clock used for lock metadata timestamps. |

| Throws | When |
|---|---|
| `\RuntimeException` | When $locksDir is empty. |

</details>

<details>
<summary><code>handle()</code></summary>

#### `FileLockMiddleware handle()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Middleware/FileLockMiddleware.php#L55)

```php
public function handle(string $taskName, array $context, callable $next): mixed
```

Handle task execution step.

| Param | Type | Description |
|---|---|---|
| `$taskName` | `string` | — |
| `$context` | `array<string, mixed>` | — |
| `$next` | `callable(): mixed` | — |

**Returns:** `mixed`

| Throws | When |
|---|---|
| [`TaskLockedException`](#tasklockedexception) | When lock cannot be acquired. |

</details>

<details>
<summary><code>sanitizeTaskName()</code></summary>

#### `FileLockMiddleware sanitizeTaskName()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Middleware/FileLockMiddleware.php#L96)

```php
private function sanitizeTaskName(string $name): string
```

Sanitize task name to a safe filename component.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |

**Returns:** `string`

</details>

## `MonitoringInterface`

*interface* — `rafalmasiarek\DashboardKitScheduler\MonitoringInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/MonitoringInterface.php#L15)

Monitoring hooks for Scheduler. Implementations may send pings to HealthChecks.io, store metrics, etc. All methods MUST swallow their own exceptions so monitoring never breaks scheduling.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`onTaskRunStart()`](#monitoringinterface-ontaskrunstart) | public | `void` | Called right before running a task. |
| [`onTaskRunOk()`](#monitoringinterface-ontaskrunok) | public | `void` | Called after a successful task run. |
| [`onTaskRunFail()`](#monitoringinterface-ontaskrunfail) | public | `void` | Called after a failed task run. |

<details>
<summary><code>onTaskRunStart()</code></summary>

#### `MonitoringInterface onTaskRunStart()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/MonitoringInterface.php#L24)

```php
public function onTaskRunStart(string $taskId, array $context): void
```

Called right before running a task.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$context` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>onTaskRunOk()</code></summary>

#### `MonitoringInterface onTaskRunOk()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/MonitoringInterface.php#L34)

```php
public function onTaskRunOk(string $taskId, array $record, array $ctx): void
```

Called after a successful task run.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$record` | `array<string, mixed>` | Full task run record from Scheduler::runTask(). |
| `$ctx` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>onTaskRunFail()</code></summary>

#### `MonitoringInterface onTaskRunFail()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/MonitoringInterface.php#L45)

```php
public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $ctx): void
```

Called after a failed task run.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$record` | `array<string, mixed>` | Full task run record from Scheduler::runTask(). |
| `$e` | `\Throwable` | Exception thrown by the task. |
| `$ctx` | `array<string, mixed>` | — |

</details>

## `NoopMonitoring`

*final class* — `rafalmasiarek\DashboardKitScheduler\NoopMonitoring` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/NoopMonitoring.php#L14)

Default no-op monitoring implementation. Safe to use in all environments where monitoring is not configured.

implements [`MonitoringInterface`](#monitoringinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`onTaskRunStart()`](#noopmonitoring-ontaskrunstart) | public | `void` | Called right before running a task. |
| [`onTaskRunOk()`](#noopmonitoring-ontaskrunok) | public | `void` | Called after a successful task run. |
| [`onTaskRunFail()`](#noopmonitoring-ontaskrunfail) | public | `void` | Called after a failed task run. |

<details>
<summary><code>onTaskRunStart()</code></summary>

#### `NoopMonitoring onTaskRunStart()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/NoopMonitoring.php#L19)

```php
public function onTaskRunStart(string $taskId, array $context): void
```

Called right before running a task.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$context` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>onTaskRunOk()</code></summary>

#### `NoopMonitoring onTaskRunOk()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/NoopMonitoring.php#L27)

```php
public function onTaskRunOk(string $taskId, array $record, array $context): void
```

Called after a successful task run.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$record` | `array<string, mixed>` | Full task run record from Scheduler::runTask(). |
| `$context` | `array` | — |

</details>

<details>
<summary><code>onTaskRunFail()</code></summary>

#### `NoopMonitoring onTaskRunFail()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/NoopMonitoring.php#L35)

```php
public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $context): void
```

Called after a failed task run.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$record` | `array<string, mixed>` | Full task run record from Scheduler::runTask(). |
| `$e` | `\Throwable` | Exception thrown by the task. |
| `$context` | `array` | — |

</details>

## `AbstractOutputPolicy`

*abstract class* — `rafalmasiarek\DashboardKitScheduler\Output\AbstractOutputPolicy` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/AbstractOutputPolicy.php#L15)

Base implementation for task result output policies. Always includes "final" (the last run's own result); includes "steps" (one entry per run record) only when the "verbose" option is enabled.

implements [`OutputPolicyInterface`](#outputpolicyinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`buildResult()`](#abstractoutputpolicy-buildresult) | public | `array<string, mixed>` | Build the normalized result payload for a task. |
| [`buildSteps()`](#abstractoutputpolicy-buildsteps) | protected | `array<int, array<string, mixed>>` | Build the "steps" section: one normalized entry per run record. |
| [`extractFinal()`](#abstractoutputpolicy-extractfinal) | protected | `mixed` | Extract the "final" value from the last run record. |
| [`isEnabled()`](#abstractoutputpolicy-isenabled) | protected | `bool` | Read a boolean-ish output option. Accepts native booleans, non-zero ints, and the strings "1"/"true"/"yes"/"on". |
| [`buildStep()`](#abstractoutputpolicy-buildstep) | protected | `array<string, mixed>` | Build the normalized payload for a single run record. |

<details>
<summary><code>buildResult()</code></summary>

#### `AbstractOutputPolicy buildResult()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/AbstractOutputPolicy.php#L20)

```php
public function buildResult(string $taskName, array $runs, array $options): array
```

Build the normalized result payload for a task.

| Param | Type | Description |
|---|---|---|
| `$taskName` | `string` | Registered task name (e.g. "my_module.my_task"). |
| `$runs` | `array<int, array<string, mixed>>` | Run records in execution order, oldest first. |
| `$options` | `array<string, mixed>` | Output options (e.g. ['verbose' => true]). |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>buildSteps()</code></summary>

#### `AbstractOutputPolicy buildSteps()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/AbstractOutputPolicy.php#L40)

```php
protected function buildSteps(array $runs): array
```

Build the "steps" section: one normalized entry per run record.

| Param | Type | Description |
|---|---|---|
| `$runs` | `array<int, array<string, mixed>>` | — |

**Returns:** `array<int, array<string, mixed>>`

</details>

<details>
<summary><code>extractFinal()</code></summary>

#### `AbstractOutputPolicy extractFinal()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/AbstractOutputPolicy.php#L62)

```php
protected function extractFinal(array $runs): mixed
```

Extract the "final" value from the last run record.

| Param | Type | Description |
|---|---|---|
| `$runs` | `array<int, array<string, mixed>>` | — |

**Returns:** `mixed`

</details>

<details>
<summary><code>isEnabled()</code></summary>

#### `AbstractOutputPolicy isEnabled()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/AbstractOutputPolicy.php#L79)

```php
protected function isEnabled(array $options, string $key): bool
```

Read a boolean-ish output option. Accepts native booleans, non-zero ints, and the strings "1"/"true"/"yes"/"on".

| Param | Type | Description |
|---|---|---|
| `$options` | `array<string, mixed>` | — |
| `$key` | `string` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>buildStep()</code></summary>

#### `AbstractOutputPolicy buildStep()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/AbstractOutputPolicy.php#L110)

```php
protected function buildStep(array $run): array
```

Build the normalized payload for a single run record.

| Param | Type | Description |
|---|---|---|
| `$run` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

## `CronOutputBuilder`

*final class* — `rafalmasiarek\DashboardKitScheduler\Output\CronOutputBuilder` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/CronOutputBuilder.php#L13)

Builds the normalized response payload shared by RunHandler and JobHandler, regardless of whether the task ran directly or through a queue.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`build()`](#cronoutputbuilder-build) | public static | `array<string, mixed>` | Build the normalized payload for a task's run history. |

<details>
<summary><code>build()</code></summary>

#### `CronOutputBuilder build()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/CronOutputBuilder.php#L26)

```php
public static function build(string $taskName, string $mode, array $runs, ?array $queueMeta, array $options): array
```

Build the normalized payload for a task's run history.

| Param | Type | Description |
|---|---|---|
| `$taskName` | `string` | — |
| `$mode` | `string` | "direct" or "queue". |
| `$runs` | `array<int, array<string, mixed>>` | Run records in execution order, oldest first. |
| `$queueMeta` | `array<string, mixed>\|null` | Queue identifiers (id/index/part), null outside a queue. |
| `$options` | `array<string, mixed>` | Output options (e.g. ['verbose' => true]). |

**Returns:** `array<string, mixed>`

</details>

## `DefaultOutputPolicy`

*final class* — `rafalmasiarek\DashboardKitScheduler\Output\DefaultOutputPolicy` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/DefaultOutputPolicy.php#L13)

Default, compact task result policy. Used for any task without a registered task-specific policy.

extends [`AbstractOutputPolicy`](#abstractoutputpolicy)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`buildStep()`](#defaultoutputpolicy-buildstep) | protected | `array<string, mixed>` | Build the normalized payload for a single run record. |

<details>
<summary><code>buildStep()</code></summary>

#### `DefaultOutputPolicy buildStep()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/DefaultOutputPolicy.php#L18)

```php
protected function buildStep(array $run): array
```

Build the normalized payload for a single run record.

| Param | Type | Description |
|---|---|---|
| `$run` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

## `OutputPolicyInterface`

*interface* — `rafalmasiarek\DashboardKitScheduler\Output\OutputPolicyInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/OutputPolicyInterface.php#L15)

Builds a normalized result payload from one or more scheduler run records. A policy decides which sections belong in "result" based on output options (currently just "verbose", read from the request's passthrough query).

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`buildResult()`](#outputpolicyinterface-buildresult) | public | `array<string, mixed>` | Build the normalized result payload for a task. |

<details>
<summary><code>buildResult()</code></summary>

#### `OutputPolicyInterface buildResult()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/OutputPolicyInterface.php#L26)

```php
public function buildResult(string $taskName, array $runs, array $options): array
```

Build the normalized result payload for a task.

| Param | Type | Description |
|---|---|---|
| `$taskName` | `string` | Registered task name (e.g. "my_module.my_task"). |
| `$runs` | `array<int, array<string, mixed>>` | Run records in execution order, oldest first. |
| `$options` | `array<string, mixed>` | Output options (e.g. ['verbose' => true]). |

**Returns:** `array<string, mixed>`

</details>

## `OutputPolicyResolver`

*final class* — `rafalmasiarek\DashboardKitScheduler\Output\OutputPolicyResolver` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/OutputPolicyResolver.php#L15)

Resolves the output policy for a given task name. Falls back to DefaultOutputPolicy for any task without a registered task-specific policy.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$policies` | `array<string, class-string<OutputPolicyInterface>>` | private static | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#outputpolicyresolver-register) | public static | `void` | Register a task-specific output policy class. |
| [`for()`](#outputpolicyresolver-for) | public static | [`OutputPolicyInterface`](#outputpolicyinterface) | Resolve the output policy instance for a task. |

<details>
<summary><code>register()</code></summary>

#### `OutputPolicyResolver register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/OutputPolicyResolver.php#L30)

```php
public static function register(string $taskName, string $policyClass): void
```

Register a task-specific output policy class.

| Param | Type | Description |
|---|---|---|
| `$taskName` | `string` | Full task name (e.g. "my_module.my_task"). |
| `$policyClass` | `class-string<OutputPolicyInterface>` | — |

</details>

<details>
<summary><code>for()</code></summary>

#### `OutputPolicyResolver for()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Output/OutputPolicyResolver.php#L42)

```php
public static function for(string $taskName): OutputPolicyInterface
```

Resolve the output policy instance for a task.

| Param | Type | Description |
|---|---|---|
| `$taskName` | `string` | — |

**Returns:** [`OutputPolicyInterface`](#outputpolicyinterface)

</details>

## `PdoStateStore`

*final class* — `rafalmasiarek\DashboardKitScheduler\PdoStateStore` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/PdoStateStore.php#L20)

PDO-based implementation of SchedulerStateStore. Works with SQLite, MySQL and PostgreSQL. Table schema is created automatically on construction. Logs schema.created to the provided logger the first time the table is created.

implements [`SchedulerStateStore`](#schedulerstatestore)

**Depends on:** `PDO`, `LoggerInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$pdo` | `PDO` | private | — |
| `$table` | `string` | private | — |
| `$logger` | `LoggerInterface\|null` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#pdostatestore-__construct) | public | — | — |
| [`read()`](#pdostatestore-read) | public | `string\|null` | Read a value by key. |
| [`write()`](#pdostatestore-write) | public | `void` | Write a value by key. |
| [`delete()`](#pdostatestore-delete) | public | `void` | Delete a value by key. |
| [`initSchema()`](#pdostatestore-initschema) | public | `void` | Create the state table if it does not exist. Logs schema.created at info level the first time the table is actually created. |
| [`tableExists()`](#pdostatestore-tableexists) | private | `bool` | Check whether the state table exists by attempting a lightweight query. |

<details>
<summary><code>__construct()</code></summary>

#### `PdoStateStore __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/PdoStateStore.php#L42)

```php
public function __construct(PDO $pdo, ?string $table, ?LoggerInterface $logger)
```

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | Ready PDO connection. |
| `$table` | `string\|null` | Table name (default: "scheduler_state"). |
| `$logger` | `LoggerInterface\|null` | Optional logger for schema creation events. |

</details>

<details>
<summary><code>read()</code></summary>

#### `PdoStateStore read()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/PdoStateStore.php#L54)

```php
public function read(string $key): ?string
```

Read a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |

**Returns:** `string\|null`

</details>

<details>
<summary><code>write()</code></summary>

#### `PdoStateStore write()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/PdoStateStore.php#L63)

```php
public function write(string $key, string $value): void
```

Write a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$value` | `string` | — |

</details>

<details>
<summary><code>delete()</code></summary>

#### `PdoStateStore delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/PdoStateStore.php#L71)

```php
public function delete(string $key): void
```

Delete a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |

</details>

<details>
<summary><code>initSchema()</code></summary>

#### `PdoStateStore initSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/PdoStateStore.php#L83)

```php
public function initSchema(): void
```

Create the state table if it does not exist. Logs schema.created at info level the first time the table is actually created.

</details>

<details>
<summary><code>tableExists()</code></summary>

#### `PdoStateStore tableExists()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/PdoStateStore.php#L118)

```php
private function tableExists(): bool
```

Check whether the state table exists by attempting a lightweight query.

**Returns:** `bool`

</details>

## `QueuePayload`

*final class* — `rafalmasiarek\DashboardKitScheduler\QueuePayload` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L16)

Per-queue key-value storage persisted inside the queue state JSON file. In direct mode (no queue_state_path), it falls back to in-memory storage and never throws. In queue mode, coordinates writes via a "<path>.lock" file using the same convention as the queue runner.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$queueStatePath` | `string\|null` | private | When null the payload is in-memory only (no persistence). |
| `$memory` | `array<string, mixed>` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#queuepayload-__construct) | public | — | — |
| [`fromContext()`](#queuepayload-fromcontext) | public static | `self` | Create payload accessor from scheduler context array. Accepts common context shapes: - flat: ['queue_state_path' => '...'] - nested: ['context' => ['queue_state_path' => '...']] - nested: ['data' => ['queue_state_path' => '...']] Returns in-memory instance when path is missing. |
| [`isPersistent()`](#queuepayload-ispersistent) | public | `bool` | Whether the payload is persisted to the queue file. |
| [`get()`](#queuepayload-get) | public | `mixed` | Get a value by key. |
| [`put()`](#queuepayload-put) | public | `void` | Store a value by key. |
| [`delete()`](#queuepayload-delete) | public | `void` | Remove a value by key. |
| [`clear()`](#queuepayload-clear) | public | `void` | Clear all payload values. |
| [`withLock()`](#queuepayload-withlock) | private | `T` | Execute callback while holding an exclusive lock on the queue file. |
| [`readStateUnlocked()`](#queuepayload-readstateunlocked) | private | `array<string, mixed>` | Read full queue state JSON without locking (caller must hold lock). |
| [`writeStateUnlocked()`](#queuepayload-writestateunlocked) | private | `void` | Persist full queue state JSON atomically without locking (caller must hold lock). |
| [`readPayload()`](#queuepayload-readpayload) | private | `array<string, mixed>` | Extract the payload sub-array from a queue state array. |

<details>
<summary><code>__construct()</code></summary>

#### `QueuePayload __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L33)

```php
public function __construct(?string $queueStatePath)
```

| Param | Type | Description |
|---|---|---|
| `$queueStatePath` | `string\|null` | Absolute path to the queue JSON file, or null for in-memory. |

</details>

<details>
<summary><code>fromContext()</code></summary>

#### `QueuePayload fromContext()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L51)

```php
public static function fromContext(array $context): self
```

Create payload accessor from scheduler context array. Accepts common context shapes: - flat:   ['queue_state_path' => '...'] - nested: ['context' => ['queue_state_path' => '...']] - nested: ['data' => ['queue_state_path' => '...']] Returns in-memory instance when path is missing.

| Param | Type | Description |
|---|---|---|
| `$context` | `array<string, mixed>` | — |

**Returns:** `self`

</details>

<details>
<summary><code>isPersistent()</code></summary>

#### `QueuePayload isPersistent()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L76)

```php
public function isPersistent(): bool
```

Whether the payload is persisted to the queue file.

**Returns:** `bool`

</details>

<details>
<summary><code>get()</code></summary>

#### `QueuePayload get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L88)

```php
public function get(string $key, mixed $default): mixed
```

Get a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$default` | `mixed` | — |

**Returns:** `mixed`

</details>

<details>
<summary><code>put()</code></summary>

#### `QueuePayload put()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L109)

```php
public function put(string $key, mixed $value): void
```

Store a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$value` | `mixed` | — |

</details>

<details>
<summary><code>delete()</code></summary>

#### `QueuePayload delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L134)

```php
public function delete(string $key): void
```

Remove a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |

</details>

<details>
<summary><code>clear()</code></summary>

#### `QueuePayload clear()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L158)

```php
public function clear(): void
```

Clear all payload values.

</details>

<details>
<summary><code>withLock()</code></summary>

#### `QueuePayload withLock()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L179)

```php
private function withLock(callable $fn): 
```

Execute callback while holding an exclusive lock on the queue file.

| Param | Type | Description |
|---|---|---|
| `$fn` | `callable(): T` | — |

**Returns:** `T`

</details>

<details>
<summary><code>readStateUnlocked()</code></summary>

#### `QueuePayload readStateUnlocked()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L216)

```php
private function readStateUnlocked(): array
```

Read full queue state JSON without locking (caller must hold lock).

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>writeStateUnlocked()</code></summary>

#### `QueuePayload writeStateUnlocked()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L237)

```php
private function writeStateUnlocked(array $state): void
```

Persist full queue state JSON atomically without locking (caller must hold lock).

| Param | Type | Description |
|---|---|---|
| `$state` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>readPayload()</code></summary>

#### `QueuePayload readPayload()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/QueuePayload.php#L266)

```php
private function readPayload(array $state): array
```

Extract the payload sub-array from a queue state array.

| Param | Type | Description |
|---|---|---|
| `$state` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

## `Scheduler`

*class* — `rafalmasiarek\DashboardKitScheduler\Scheduler` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L24)

In-process cron-expression task scheduler. - Stores last-run timestamps via a pluggable SchedulerStateStore. - Prevents duplicate executions within the same minute. - Supports catch-up of missed runs via dragonmantank/cron-expression. - Tasks can carry a per-task middleware pipeline (e.g. file locks). - Tasks are indexed by name; registration is idempotent within a request.

**Depends on:** [`SchedulerStateStore`](#schedulerstatestore), [`MonitoringInterface`](#monitoringinterface), `ClockInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$tasks` | `array<string, array{expression: string, callback: callable, middlewares: array<int, TaskMiddlewareInterface>, cron: ?CronExpression, opt: array<string, mixed>, allow_concurrent: bool}>` | private | Registered tasks indexed by task name. |
| `$defaultNoOverlap` | `bool` | private | When true, tasks are non-overlapping by default. |
| `$locksDir` | `string` | private | Directory for per-task lock files. |
| `$store` | [`SchedulerStateStore`](#schedulerstatestore) | private | Pluggable state store for last-run timestamps. |
| `$monitoring` | [`MonitoringInterface`](#monitoringinterface) | private | Optional monitoring implementation. |
| `$clock` | `ClockInterface` | private | Shared time source. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#scheduler-__construct) | public | — | Create a new Scheduler instance. When $store is null, a FileStateStore is created using $runtimeDir (or the SCHEDULER_STATE_DIR environment variable, falling back to "<package-dir>/../storage"). |
| [`state()`](#scheduler-state) | public | [`SchedulerStateStore`](#schedulerstatestore) | Expose the shared state store. |
| [`payloadFrom()`](#scheduler-payloadfrom) | public | [`QueuePayload`](#queuepayload) | Build a QueuePayload accessor from a context array. |
| [`add()`](#scheduler-add) | public | `void` | Register a task. A FileLockMiddleware is prepended automatically when $defaultNoOverlap is true and $allowConcurrent is false. |
| [`addWithMiddlewares()`](#scheduler-addwithmiddlewares) | public | `void` | Register a task with an explicit middleware list. When $defaultNoOverlap is true and $allowConcurrent is false, a FileLockMiddleware is prepended before the provided list. |
| [`listDueTasks()`](#scheduler-listduetasks) | public | `string[]` | List names of tasks that are due at the given time. |
| [`describeTasks()`](#scheduler-describetasks) | public | `array<string, array{name: string, expression: string, last_run_ts: int\|null, last_run_at: string\|null, is_due: bool}>` | Describe all registered tasks with last-run state and due flag. |
| [`runDueTasksDetailed()`](#scheduler-runduetasksdetailed) | public | `array<int, array{name: string, started_at: string, finished_at: string, duration_ms: int, status: string, result: mixed, error: array{type: string, message: string}\|null}>` | Run all due tasks and return structured records. |
| [`runDueTasks()`](#scheduler-runduetasks) | public | `string[]` | Run all due tasks and return names of successfully executed tasks. |
| [`runTask()`](#scheduler-runtask) | public | `array<string, mixed>` | Execute a single task run. When $force is false the task runs only if due (or allowed step execution via context). last_started_at is stamped early to reduce minute-later re-trigger attempts. |
| [`isDueOrMissed()`](#scheduler-isdueormissed) | private | `bool` | Decide whether a task is due or has a missed run at $now. Concurrency policy: - allow_concurrent: schedule solely from last_run. - non-overlapping: use last_started_at as in-progress anchor when fresh. Stale run detection: started_at older than max_runtime_sec (default 3600) is ignored. |
| [`lastStartedAt()`](#scheduler-laststartedat) | private | `int\|null` | Read the last_started_at timestamp for a task. |
| [`stampStartedAt()`](#scheduler-stampstartedat) | private | `int` | Stamp last_started_at to mark task as in-progress. |
| [`clearStartedAt()`](#scheduler-clearstartedat) | private | `void` | Clear last_started_at after task completion. Uses "0" sentinel for stores that do not support key deletion. |
| [`lastRun()`](#scheduler-lastrun) | private | `int\|null` | Read last_run timestamp for a task. |
| [`markAsRun()`](#scheduler-markasrun) | private | `int` | Persist last_run timestamp after successful execution. |
| [`buildCronExpression()`](#scheduler-buildcronexpression) | private | `CronExpression` | Build and validate a CronExpression. |
| [`getCronForTask()`](#scheduler-getcronfortask) | private | `CronExpression` | Return the cached CronExpression for a registered task. |
| [`executeWithMiddlewares()`](#scheduler-executewithmiddlewares) | private | `mixed` | Execute task callback through the registered middleware pipeline. |
| [`invokeCallbackCompat()`](#scheduler-invokecallbackcompat) | private | `mixed` | Invoke callback with backward-compatible signature support. Supports callable(TaskContext) and callable(). |
| [`buildRuntimeOptions()`](#scheduler-buildruntimeoptions) | private | `array<string, mixed>` | Build final runtime options from base config and request context. Rules: - Start from task base options (registered with add()). - Apply overrides from context (GET params / queue passthrough). - Context wins when value !== null and !== ''. - Internal scheduler/queue keys are stripped. - Scalars are coerced to base option types (bool/int/float). |
| [`coerceOverridesByBaseTypes()`](#scheduler-coerceoverridesbybasetypes) | private | `array<string, mixed>` | Coerce override scalar values to match the types declared in $base. |
| [`toBool()`](#scheduler-tobool) | private | `bool` | Parse a boolean from common GET/ENV forms. |

<details>
<summary><code>__construct()</code></summary>

#### `Scheduler __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L94)

```php
public function __construct(?SchedulerStateStore $store, ?string $runtimeDir, mixed $timezone, ?MonitoringInterface $monitoring, ?ClockInterface $clock, ?string $locksDir, bool $defaultNoOverlap)
```

Create a new Scheduler instance. When $store is null, a FileStateStore is created using $runtimeDir (or the SCHEDULER_STATE_DIR environment variable, falling back to "<package-dir>/../storage").

| Param | Type | Description |
|---|---|---|
| `$store` | `SchedulerStateStore\|null` | Pluggable state store. |
| `$runtimeDir` | `string\|null` | Path for the default FileStateStore. |
| `$timezone` | `mixed` | Deprecated, ignored — pass $clock instead. |
| `$monitoring` | `MonitoringInterface\|null` | Monitoring implementation (default: NoopMonitoring). |
| `$clock` | `ClockInterface\|null` | Shared time source. |
| `$locksDir` | `string\|null` | Directory for lock files. |
| `$defaultNoOverlap` | `bool` | Whether tasks are non-overlapping by default. |

</details>

<details>
<summary><code>state()</code></summary>

#### `Scheduler state()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L134)

```php
public function state(): SchedulerStateStore
```

Expose the shared state store.

**Returns:** [`SchedulerStateStore`](#schedulerstatestore)

</details>

<details>
<summary><code>payloadFrom()</code></summary>

#### `Scheduler payloadFrom()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L145)

```php
public function payloadFrom(array $ctx): QueuePayload
```

Build a QueuePayload accessor from a context array.

| Param | Type | Description |
|---|---|---|
| `$ctx` | `array<string, mixed>` | — |

**Returns:** [`QueuePayload`](#queuepayload)

</details>

<details>
<summary><code>add()</code></summary>

#### `Scheduler add()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L163)

```php
public function add(string $expression, callable $callback, string $name, array $optBase, bool $allowConcurrent): void
```

Register a task. A FileLockMiddleware is prepended automatically when $defaultNoOverlap is true and $allowConcurrent is false.

| Param | Type | Description |
|---|---|---|
| `$expression` | `string` | Cron expression (e.g. "* * * * *"). |
| `$callback` | `callable` | Task callback; receives TaskContext when it accepts >= 1 parameter. |
| `$name` | `string` | Unique task name. |
| `$optBase` | `array<string, mixed>` | Base options merged with runtime context at execution. |
| `$allowConcurrent` | `bool` | When true, disables the default no-overlap lock. |

</details>

<details>
<summary><code>addWithMiddlewares()</code></summary>

#### `Scheduler addWithMiddlewares()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L202)

```php
public function addWithMiddlewares(string $expression, callable $callback, string $name, array $middlewares, array $optBase, bool $allowConcurrent): void
```

Register a task with an explicit middleware list. When $defaultNoOverlap is true and $allowConcurrent is false, a FileLockMiddleware is prepended before the provided list.

| Param | Type | Description |
|---|---|---|
| `$expression` | `string` | — |
| `$callback` | `callable` | — |
| `$name` | `string` | — |
| `$middlewares` | `array<int, TaskMiddlewareInterface>` | — |
| `$optBase` | `array<string, mixed>` | — |
| `$allowConcurrent` | `bool` | — |

</details>

<details>
<summary><code>listDueTasks()</code></summary>

#### `Scheduler listDueTasks()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L241)

```php
public function listDueTasks(?\DateTimeInterface $now): array
```

List names of tasks that are due at the given time.

| Param | Type | Description |
|---|---|---|
| `$now` | `\DateTimeInterface\|null` | — |

**Returns:** `string[]`

</details>

<details>
<summary><code>describeTasks()</code></summary>

#### `Scheduler describeTasks()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L261)

```php
public function describeTasks(?\DateTimeInterface $now): array
```

Describe all registered tasks with last-run state and due flag.

| Param | Type | Description |
|---|---|---|
| `$now` | `\DateTimeInterface\|null` | — |

**Returns:** `array<string, array{name: string, expression: string, last_run_ts: int\|null, last_run_at: string\|null, is_due: bool}>`

</details>

<details>
<summary><code>runDueTasksDetailed()</code></summary>

#### `Scheduler runDueTasksDetailed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L292)

```php
public function runDueTasksDetailed(): array
```

Run all due tasks and return structured records.

**Returns:** `array<int, array{name: string, started_at: string, finished_at: string, duration_ms: int, status: string, result: mixed, error: array{type: string, message: string}\|null}>`

</details>

<details>
<summary><code>runDueTasks()</code></summary>

#### `Scheduler runDueTasks()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L322)

```php
public function runDueTasks(): array
```

Run all due tasks and return names of successfully executed tasks.

**Returns:** `string[]`

</details>

<details>
<summary><code>runTask()</code></summary>

#### `Scheduler runTask()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L347)

```php
public function runTask(string $name, bool $force, ?array $context): array
```

Execute a single task run. When $force is false the task runs only if due (or allowed step execution via context). last_started_at is stamped early to reduce minute-later re-trigger attempts.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |
| `$force` | `bool` | When true, bypasses due-check and does not persist state. |
| `$context` | `array<string, mixed>\|null` | Runtime context (queue params, passthrough query params, etc.). |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>isDueOrMissed()</code></summary>

#### `Scheduler isDueOrMissed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L535)

```php
private function isDueOrMissed(string $name, \DateTimeInterface $now): bool
```

Decide whether a task is due or has a missed run at $now. Concurrency policy: - allow_concurrent: schedule solely from last_run. - non-overlapping: use last_started_at as in-progress anchor when fresh. Stale run detection: started_at older than max_runtime_sec (default 3600) is ignored.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |
| `$now` | `\DateTimeInterface` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>lastStartedAt()</code></summary>

#### `Scheduler lastStartedAt()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L614)

```php
private function lastStartedAt(string $name): ?int
```

Read the last_started_at timestamp for a task.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |

**Returns:** `int\|null`

</details>

<details>
<summary><code>stampStartedAt()</code></summary>

#### `Scheduler stampStartedAt()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L632)

```php
private function stampStartedAt(string $name, ?\DateTimeInterface $now): int
```

Stamp last_started_at to mark task as in-progress.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |
| `$now` | `\DateTimeInterface\|null` | — |

**Returns:** `int`

</details>

<details>
<summary><code>clearStartedAt()</code></summary>

#### `Scheduler clearStartedAt()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L649)

```php
private function clearStartedAt(string $name): void
```

Clear last_started_at after task completion. Uses "0" sentinel for stores that do not support key deletion.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |

</details>

<details>
<summary><code>lastRun()</code></summary>

#### `Scheduler lastRun()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L660)

```php
private function lastRun(string $name): ?int
```

Read last_run timestamp for a task.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |

**Returns:** `int\|null`

</details>

<details>
<summary><code>markAsRun()</code></summary>

#### `Scheduler markAsRun()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L674)

```php
private function markAsRun(string $name, ?CronExpression $expr, ?\DateTimeInterface $now): int
```

Persist last_run timestamp after successful execution.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |
| `$expr` | `CronExpression\|null` | Unused; kept for signature compatibility. |
| `$now` | `\DateTimeInterface\|null` | — |

**Returns:** `int`

</details>

<details>
<summary><code>buildCronExpression()</code></summary>

#### `Scheduler buildCronExpression()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L691)

```php
private function buildCronExpression(string $expression, string $taskName): CronExpression
```

Build and validate a CronExpression.

| Param | Type | Description |
|---|---|---|
| `$expression` | `string` | — |
| `$taskName` | `string` | — |

**Returns:** `CronExpression`

| Throws | When |
|---|---|
| `\InvalidArgumentException` | When the expression is invalid. |

</details>

<details>
<summary><code>getCronForTask()</code></summary>

#### `Scheduler getCronForTask()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L710)

```php
private function getCronForTask(string $name): CronExpression
```

Return the cached CronExpression for a registered task.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |

**Returns:** `CronExpression`

</details>

<details>
<summary><code>executeWithMiddlewares()</code></summary>

#### `Scheduler executeWithMiddlewares()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L733)

```php
private function executeWithMiddlewares(string $name, callable $cb, array $ctx, TaskContext $taskContext): mixed
```

Execute task callback through the registered middleware pipeline.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | — |
| `$cb` | `callable` | — |
| `$ctx` | `array<string, mixed>` | — |
| `$taskContext` | [`TaskContext`](#taskcontext) | — |

**Returns:** `mixed`

</details>

<details>
<summary><code>invokeCallbackCompat()</code></summary>

#### `Scheduler invokeCallbackCompat()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L768)

```php
private function invokeCallbackCompat(callable $cb, TaskContext $context): mixed
```

Invoke callback with backward-compatible signature support. Supports callable(TaskContext) and callable().

| Param | Type | Description |
|---|---|---|
| `$cb` | `callable` | — |
| `$context` | [`TaskContext`](#taskcontext) | — |

**Returns:** `mixed`

</details>

<details>
<summary><code>buildRuntimeOptions()</code></summary>

#### `Scheduler buildRuntimeOptions()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L802)

```php
private function buildRuntimeOptions(array $base, array $ctx): array
```

Build final runtime options from base config and request context. Rules: - Start from task base options (registered with add()). - Apply overrides from context (GET params / queue passthrough). - Context wins when value !== null and !== ''. - Internal scheduler/queue keys are stripped. - Scalars are coerced to base option types (bool/int/float).

| Param | Type | Description |
|---|---|---|
| `$base` | `array<string, mixed>` | — |
| `$ctx` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>coerceOverridesByBaseTypes()</code></summary>

#### `Scheduler coerceOverridesByBaseTypes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L822)

```php
private function coerceOverridesByBaseTypes(array $base, array $overrides): array
```

Coerce override scalar values to match the types declared in $base.

| Param | Type | Description |
|---|---|---|
| `$base` | `array<string, mixed>` | — |
| `$overrides` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>toBool()</code></summary>

#### `Scheduler toBool()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Scheduler.php#L849)

```php
private function toBool(mixed $v): bool
```

Parse a boolean from common GET/ENV forms.

| Param | Type | Description |
|---|---|---|
| `$v` | `mixed` | — |

**Returns:** `bool`

</details>

## `SchedulerAddon`

*final class* — `rafalmasiarek\DashboardKitScheduler\SchedulerAddon` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerAddon.php#L27)

Wires the scheduler engine into a Dashboard application.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#scheduleraddon-register) | public static | `void` | Register the scheduler addon. |
| [`loadSystemTasks()`](#scheduleraddon-loadsystemtasks) | private static | `void` | Register system-level tasks defined in app.config['cron']. Each task's global ID is: system.<task-id> Config format: 'cron' => [ 'my-task' => [ 'cron' => '0 3 * * *', 'handler' => static function (): void { ... }, 'enabled' => true, // optional, default true ], ], |
| [`loadTasks()`](#scheduleraddon-loadtasks) | private static | `void` | Discover and register tasks from all modules that define a 'schedule' key. Each task's global ID is: <module-slug>.<task-id> |
| [`buildTaskRegistry()`](#scheduleraddon-buildtaskregistry) | private static | [`TaskRegistry`](#taskregistry) | Build the built-in task factory registry. |
| [`cleanupQueueFiles()`](#scheduleraddon-cleanupqueuefiles) | private static | `void` | Remove queue JSON files older than 24 hours from the queue directory. |

<details>
<summary><code>register()</code></summary>

#### `SchedulerAddon register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerAddon.php#L37)

```php
public static function register(App $app, ContainerInterface $container, array $config): void
```

Register the scheduler addon.

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | — |
| `$config` | `array<string, mixed>` | Optional configuration overrides. |

</details>

<details>
<summary><code>loadSystemTasks()</code></summary>

#### `SchedulerAddon loadSystemTasks()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerAddon.php#L194)

```php
private static function loadSystemTasks(Scheduler $scheduler, array $appConfig, ContainerInterface $container, ClockInterface $clock, ?LoggerInterface $logger): void
```

Register system-level tasks defined in app.config['cron']. Each task's global ID is: system.<task-id> Config format: 'cron' => [ 'my-task' => [ 'cron'    => '0 3 * * *', 'handler' => static function (): void { ... }, 'enabled' => true,  // optional, default true ], ],

| Param | Type | Description |
|---|---|---|
| `$scheduler` | [`Scheduler`](#scheduler) | — |
| `$appConfig` | `array<string, mixed>` | — |
| `$container` | `ContainerInterface` | — |
| `$clock` | `ClockInterface` | — |
| `$logger` | `LoggerInterface\|null` | — |

</details>

<details>
<summary><code>loadTasks()</code></summary>

#### `SchedulerAddon loadTasks()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerAddon.php#L262)

```php
private static function loadTasks(Scheduler $scheduler, ModuleRegistry $registry, ContainerInterface $container, ClockInterface $clock, ?LoggerInterface $logger): void
```

Discover and register tasks from all modules that define a 'schedule' key. Each task's global ID is: <module-slug>.<task-id>

| Param | Type | Description |
|---|---|---|
| `$scheduler` | [`Scheduler`](#scheduler) | — |
| `$registry` | `ModuleRegistry` | — |
| `$container` | `ContainerInterface` | — |
| `$clock` | `ClockInterface` | — |
| `$logger` | `LoggerInterface\|null` | Optional logger for config errors and load summary. |

</details>

<details>
<summary><code>buildTaskRegistry()</code></summary>

#### `SchedulerAddon buildTaskRegistry()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerAddon.php#L331)

```php
private static function buildTaskRegistry(): TaskRegistry
```

Build the built-in task factory registry.

**Returns:** [`TaskRegistry`](#taskregistry)

</details>

<details>
<summary><code>cleanupQueueFiles()</code></summary>

#### `SchedulerAddon cleanupQueueFiles()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerAddon.php#L344)

```php
private static function cleanupQueueFiles(string $dir): void
```

Remove queue JSON files older than 24 hours from the queue directory.

| Param | Type | Description |
|---|---|---|
| `$dir` | `string` | — |

</details>

## `SchedulerStateStore`

*interface* — `rafalmasiarek\DashboardKitScheduler\SchedulerStateStore` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerStateStore.php#L12)

Interface for storing scheduler state (last run timestamps, etc.).

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`read()`](#schedulerstatestore-read) | public | `string\|null` | Read a value by key. |
| [`write()`](#schedulerstatestore-write) | public | `void` | Write a value by key. |
| [`delete()`](#schedulerstatestore-delete) | public | `void` | Delete a value by key. |

<details>
<summary><code>read()</code></summary>

#### `SchedulerStateStore read()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerStateStore.php#L20)

```php
public function read(string $key): ?string
```

Read a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |

**Returns:** `string\|null`

</details>

<details>
<summary><code>write()</code></summary>

#### `SchedulerStateStore write()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerStateStore.php#L29)

```php
public function write(string $key, string $value): void
```

Write a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$value` | `string` | — |

</details>

<details>
<summary><code>delete()</code></summary>

#### `SchedulerStateStore delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SchedulerStateStore.php#L37)

```php
public function delete(string $key): void
```

Delete a value by key.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |

</details>

## `SchedulerSchemaProvider`

*final class* — `rafalmasiarek\DashboardKitScheduler\Schema\SchedulerSchemaProvider` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Schema/SchedulerSchemaProvider.php#L19)

Provides the scheduler state table schema to AuthKit's createSchema() mechanism. Usage in SchedulerAddon::register(): $auth->createSchema(new SchedulerSchemaProvider()); The SQL statements are idempotent (CREATE TABLE IF NOT EXISTS).

implements `SchemaProviderInterface`

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`additionalSchema()`](#schedulerschemaprovider-additionalschema) | public | `string[]` | Return idempotent SQL statements for the given DB driver. |

<details>
<summary><code>additionalSchema()</code></summary>

#### `SchedulerSchemaProvider additionalSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Schema/SchedulerSchemaProvider.php#L27)

```php
public function additionalSchema(string $driver): array
```

Return idempotent SQL statements for the given DB driver.

| Param | Type | Description |
|---|---|---|
| `$driver` | `string` | PDO driver name ("mysql", "sqlite", "pgsql"). |

**Returns:** `string[]`

</details>

## `SystemLogMonitoring`

*final class* — `rafalmasiarek\DashboardKitScheduler\SystemLogMonitoring` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SystemLogMonitoring.php#L23)

Logs task execution lifecycle events to a PSR-3 logger (typically logger.system). Auto-wired by SchedulerAddon when logger.system is available in the DI container and no explicit MonitoringInterface binding exists. Emitted events: task.start  — info    — emitted right before a task begins execution task.ok     — info    — emitted after successful completion task.locked — warning — emitted when concurrent execution was blocked by FileLockMiddleware task.fail   — error   — emitted when the task threw an unhandled exception

implements [`MonitoringInterface`](#monitoringinterface)

**Depends on:** `LoggerInterface`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#systemlogmonitoring-__construct) | public | — | — |
| [`onTaskRunStart()`](#systemlogmonitoring-ontaskrunstart) | public | `void` | Called right before running a task. |
| [`onTaskRunOk()`](#systemlogmonitoring-ontaskrunok) | public | `void` | Called after a successful task run. |
| [`onTaskRunFail()`](#systemlogmonitoring-ontaskrunfail) | public | `void` | Called after a failed task run. |

<details>
<summary><code>__construct()</code></summary>

#### `SystemLogMonitoring __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SystemLogMonitoring.php#L28)

```php
public function __construct(LoggerInterface $logger)
```

| Param | Type | Description |
|---|---|---|
| `$logger` | `LoggerInterface` | Logger to write lifecycle events to. |

</details>

<details>
<summary><code>onTaskRunStart()</code></summary>

#### `SystemLogMonitoring onTaskRunStart()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SystemLogMonitoring.php#L35)

```php
public function onTaskRunStart(string $taskId, array $context): void
```

Called right before running a task.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$context` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>onTaskRunOk()</code></summary>

#### `SystemLogMonitoring onTaskRunOk()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SystemLogMonitoring.php#L43)

```php
public function onTaskRunOk(string $taskId, array $record, array $ctx): void
```

Called after a successful task run.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$record` | `array<string, mixed>` | Full task run record from Scheduler::runTask(). |
| `$ctx` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>onTaskRunFail()</code></summary>

#### `SystemLogMonitoring onTaskRunFail()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/SystemLogMonitoring.php#L54)

```php
public function onTaskRunFail(string $taskId, array $record, \Throwable $e, array $ctx): void
```

Called after a failed task run.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | — |
| `$record` | `array<string, mixed>` | Full task run record from Scheduler::runTask(). |
| `$e` | `\Throwable` | Exception thrown by the task. |
| `$ctx` | `array<string, mixed>` | — |

</details>

## `CallableTaskFactory`

*final class* — `rafalmasiarek\DashboardKitScheduler\Task\Builtin\CallableTaskFactory` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/Builtin/CallableTaskFactory.php#L20)

Action: callable Invokes an arbitrary PHP callable (closure, "Class@method", "Class::method"). Target is read from $options['handler'] at runtime.

implements [`TaskFactoryInterface`](#taskfactoryinterface)

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`build()`](#callabletaskfactory-build) | public | `callable` | Build a task callback. |
| [`resolveCallable()`](#callabletaskfactory-resolvecallable) | private static | `callable\|null` | Resolve a callable from a mixed target value. Supports: Closure, "Class@method", "Class::method", or any PHP callable. |
| [`invokeCallableCompat()`](#callabletaskfactory-invokecallablecompat) | private | `mixed` | Invoke callable preserving the Scheduler compatibility contract. Passes TaskContext + ContainerInterface when the callable accepts >= 2 parameters, TaskContext only when it accepts 1, or no args when it accepts 0. |

<details>
<summary><code>build()</code></summary>

#### `CallableTaskFactory build()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/Builtin/CallableTaskFactory.php#L25)

```php
public function build(string $taskId, ContainerInterface $container, ClockInterface $clock, array $options): callable
```

Build a task callback.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | Task identifier. |
| `$container` | `ContainerInterface` | PSR-11 container for service resolution. |
| `$clock` | `ClockInterface` | Shared clock for task runtime. |
| `$options` | `array<string, mixed>` | Task options from the module schedule definition. |

**Returns:** `callable`

</details>

<details>
<summary><code>resolveCallable()</code></summary>

#### `CallableTaskFactory resolveCallable()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/Builtin/CallableTaskFactory.php#L50)

```php
private static function resolveCallable(mixed $target): ?callable
```

Resolve a callable from a mixed target value. Supports: Closure, "Class@method", "Class::method", or any PHP callable.

| Param | Type | Description |
|---|---|---|
| `$target` | `mixed` | — |

**Returns:** `callable\|null`

</details>

<details>
<summary><code>invokeCallableCompat()</code></summary>

#### `CallableTaskFactory invokeCallableCompat()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/Builtin/CallableTaskFactory.php#L80)

```php
private function invokeCallableCompat(callable $cb, TaskContext $context, ContainerInterface $container): mixed
```

Invoke callable preserving the Scheduler compatibility contract. Passes TaskContext + ContainerInterface when the callable accepts >= 2 parameters, TaskContext only when it accepts 1, or no args when it accepts 0.

| Param | Type | Description |
|---|---|---|
| `$cb` | `callable` | — |
| `$context` | [`TaskContext`](#taskcontext) | — |
| `$container` | `ContainerInterface` | — |

**Returns:** `mixed`

</details>

## `TaskFactoryInterface`

*interface* — `rafalmasiarek\DashboardKitScheduler\Task\TaskFactoryInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/TaskFactoryInterface.php#L22)

Builds a task callback from a task definition. The returned callable is compatible with Scheduler callback invocation rules: - It may accept a single TaskContext parameter. - It may accept no parameters (legacy compatibility). Factories must not concern themselves with scheduler internals (locking, due logic, state). Their responsibility is translating config/options into executable task logic.

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`build()`](#taskfactoryinterface-build) | public | `callable` | Build a task callback. |

<details>
<summary><code>build()</code></summary>

#### `TaskFactoryInterface build()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/TaskFactoryInterface.php#L33)

```php
public function build(string $taskId, ContainerInterface $container, ClockInterface $clock, array $options): callable
```

Build a task callback.

| Param | Type | Description |
|---|---|---|
| `$taskId` | `string` | Task identifier. |
| `$container` | `ContainerInterface` | PSR-11 container for service resolution. |
| `$clock` | `ClockInterface` | Shared clock for task runtime. |
| `$options` | `array<string, mixed>` | Task options from the module schedule definition. |

**Returns:** `callable`

</details>

## `TaskRegistry`

*final class* — `rafalmasiarek\DashboardKitScheduler\Task\TaskRegistry` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/TaskRegistry.php#L15)

Action-to-factory registry. Maps action name strings (from module schedule definitions) to TaskFactoryInterface implementations. Used by SchedulerAddon to resolve the right factory per task.

**Package:** `rafalmasiarek\DashboardKitScheduler`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$factories` | `array<string, TaskFactoryInterface>` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#taskregistry-register) | public | `void` | Register a factory for an action name. |
| [`get()`](#taskregistry-get) | public | `TaskFactoryInterface\|null` | Resolve the factory for an action name. |

<details>
<summary><code>register()</code></summary>

#### `TaskRegistry register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/TaskRegistry.php#L29)

```php
public function register(string $action, TaskFactoryInterface $factory): void
```

Register a factory for an action name.

| Param | Type | Description |
|---|---|---|
| `$action` | `string` | — |
| `$factory` | [`TaskFactoryInterface`](#taskfactoryinterface) | — |

</details>

<details>
<summary><code>get()</code></summary>

#### `TaskRegistry get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/Task/TaskRegistry.php#L45)

```php
public function get(string $action): ?TaskFactoryInterface
```

Resolve the factory for an action name.

| Param | Type | Description |
|---|---|---|
| `$action` | `string` | — |

**Returns:** `TaskFactoryInterface\|null`

</details>

## `TaskContext`

*final class* — `rafalmasiarek\DashboardKitScheduler\TaskContext` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/TaskContext.php#L22)

Object wrapper passed into task callbacks. Provides named, typed access to task runtime data: - $context->taskName  — registered task name - $context->data      — raw context array from the queue/HTTP request - $context->opt       — final merged options (single source of truth) - $context->state     — scheduler state store (last_run, etc.) - $context->payload   — per-queue payload (persisted when queue_state_path present) - $context->clock     — shared clock instance

**Depends on:** [`SchedulerStateStore`](#schedulerstatestore), [`QueuePayload`](#queuepayload), `ClockInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$taskName` | `string` | public | — |
| `$data` | `array<string, mixed>` | public | — |
| `$opt` | `array<string, mixed>` | public | Final merged options for this run (config base + context overrides). |
| `$state` | [`SchedulerStateStore`](#schedulerstatestore) | public | — |
| `$payload` | [`QueuePayload`](#queuepayload) | public | — |
| `$clock` | `ClockInterface` | public | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#taskcontext-__construct) | public | — | — |
| [`get()`](#taskcontext-get) | public | `mixed` | Convenience getter for raw context keys. |
| [`mergeOpt()`](#taskcontext-mergeopt) | public static | `array<string, mixed>` | Merge options with "context wins" semantics. Null and empty-string values do not override base. |

<details>
<summary><code>__construct()</code></summary>

#### `TaskContext __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/TaskContext.php#L64)

```php
public function __construct(string $taskName, array $data, array $opt, SchedulerStateStore $state, QueuePayload $payload, ClockInterface $clock)
```

| Param | Type | Description |
|---|---|---|
| `$taskName` | `string` | — |
| `$data` | `array<string, mixed>` | — |
| `$opt` | `array<string, mixed>` | — |
| `$state` | [`SchedulerStateStore`](#schedulerstatestore) | — |
| `$payload` | [`QueuePayload`](#queuepayload) | — |
| `$clock` | `ClockInterface` | — |

</details>

<details>
<summary><code>get()</code></summary>

#### `TaskContext get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/TaskContext.php#L87)

```php
public function get(string $key, mixed $default): mixed
```

Convenience getter for raw context keys.

| Param | Type | Description |
|---|---|---|
| `$key` | `string` | — |
| `$default` | `mixed` | — |

**Returns:** `mixed`

</details>

<details>
<summary><code>mergeOpt()</code></summary>

#### `TaskContext mergeOpt()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/TaskContext.php#L101)

```php
public static function mergeOpt(array $base, array $ctx): array
```

Merge options with "context wins" semantics. Null and empty-string values do not override base.

| Param | Type | Description |
|---|---|---|
| `$base` | `array<string, mixed>` | — |
| `$ctx` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

## `TaskMiddlewareInterface`

*interface* — `rafalmasiarek\DashboardKitScheduler\TaskMiddlewareInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/TaskMiddlewareInterface.php#L14)

Middleware pipeline for task execution. Implementations must call $next() to continue execution.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`handle()`](#taskmiddlewareinterface-handle) | public | `mixed` | Handle task execution step. |

<details>
<summary><code>handle()</code></summary>

#### `TaskMiddlewareInterface handle()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler/blob/main/src/TaskMiddlewareInterface.php#L24)

```php
public function handle(string $taskName, array $context, callable $next): mixed
```

Handle task execution step.

| Param | Type | Description |
|---|---|---|
| `$taskName` | `string` | — |
| `$context` | `array<string, mixed>` | — |
| `$next` | `callable(): mixed` | — |

**Returns:** `mixed`

</details>



## License

![License: BUSL-1.1](https://img.shields.io/badge/license-BUSL--1.1-red)

Business Source License 1.1.

Source available; commercial use restricted until 2099-01-01.


For alternative licensing, contact: https://masiarek.pl/contact/?af_subject=Commercial+license+%E2%80%94+dashboard-kit-addon-scheduler&af_message=Hello%2C+I+am+interested+in+a+commercial+license+for+dashboard-kit-addon-scheduler.



See [LICENSE](LICENSE) for full details.
## Authors

- Rafał Masiarek | [website](https://masiarek.pl) | [github](https://github.com/rafalmasiarek)


<!-- references -->

[repo_link]: https://github.com/rafalmasiarek/php-dashboard-kit-addon-scheduler
