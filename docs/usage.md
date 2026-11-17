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
