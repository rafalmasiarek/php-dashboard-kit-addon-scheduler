<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * PDO-based implementation of SchedulerStateStore.
 *
 * Works with SQLite, MySQL and PostgreSQL.
 * Table schema is created automatically on construction.
 * Logs schema.created to the provided logger the first time the table is created.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class PdoStateStore implements SchedulerStateStore
{
    /**
     * @var PDO
     */
    private PDO $pdo;

    /**
     * @var string
     */
    private string $table;

    /**
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger;

    /**
     * @param PDO                  $pdo    Ready PDO connection.
     * @param string|null          $table  Table name (default: "scheduler_state").
     * @param LoggerInterface|null $logger Optional logger for schema creation events.
     */
    public function __construct(PDO $pdo, ?string $table = null, ?LoggerInterface $logger = null)
    {
        $this->pdo    = $pdo;
        $this->table  = $table ?? 'scheduler_state';
        $this->logger = $logger;

        $this->initSchema();
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $key): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT value FROM {$this->table} WHERE `key` = :k LIMIT 1"
        );
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();

        return $val === false ? null : (string) $val;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $key, string $value): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            $sql = "INSERT INTO {$this->table} (`key`, value)
                    VALUES (:k, :v)
                    ON CONFLICT(`key`) DO UPDATE SET value = excluded.value";
        } else {
            $sql = "INSERT INTO {$this->table} (`key`, value)
                    VALUES (:k, :v)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE `key` = :k"
        );
        $stmt->execute([':k' => $key]);
    }

    /**
     * Create the state table if it does not exist.
     *
     * Logs schema.created at info level the first time the table is actually created.
     *
     * @return void
     */
    public function initSchema(): void
    {
        $existed = $this->tableExists();

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite' || $driver === 'pgsql') {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    key   TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )"
            );
        } else {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$this->table} (
                    `key`   VARCHAR(255) PRIMARY KEY,
                    `value` TEXT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        if (!$existed) {
            $this->logger?->info('schema.created', [
                'table'  => $this->table,
                'driver' => $driver,
            ]);
        }
    }

    /**
     * Check whether the state table exists by attempting a lightweight query.
     *
     * @return bool
     */
    private function tableExists(): bool
    {
        try {
            $result = $this->pdo->query("SELECT 1 FROM {$this->table} LIMIT 1");
            return $result !== false;
        } catch (\PDOException) {
            return false;
        }
    }
}
