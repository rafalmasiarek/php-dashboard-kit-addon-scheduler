<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Schema;

use rafalmasiarek\AuthKit\Extension\SchemaProviderInterface;

/**
 * Provides the scheduler state table schema to AuthKit's createSchema() mechanism.
 *
 * Usage in SchedulerAddon::register():
 *   $auth->createSchema(new SchedulerSchemaProvider());
 *
 * The SQL statements are idempotent (CREATE TABLE IF NOT EXISTS).
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class SchedulerSchemaProvider implements SchemaProviderInterface
{
    /**
     * Return idempotent SQL statements for the given DB driver.
     *
     * @param string $driver PDO driver name ("mysql", "sqlite", "pgsql").
     * @return string[]
     */
    public function additionalSchema(string $driver): array
    {
        if ($driver === 'sqlite' || $driver === 'pgsql') {
            return [
                "CREATE TABLE IF NOT EXISTS scheduler_state (
                    key   TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )",
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS scheduler_state (
                `key`   VARCHAR(255) PRIMARY KEY,
                `value` TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }
}
