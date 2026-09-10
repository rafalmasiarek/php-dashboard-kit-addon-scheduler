<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * File-based implementation of SchedulerStateStore.
 *
 * Each key is stored as a separate file in the given directory.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class FileStateStore implements SchedulerStateStore
{
    /**
     * @var string
     */
    private string $dir;

    /**
     * @param string $dir Directory path for state files (created automatically if missing).
     */
    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $key): ?string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $key;
        if (!is_file($path)) {
            return null;
        }
        return file_get_contents($path) ?: null;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $key, string $value): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $key;
        file_put_contents($path, $value, LOCK_EX);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $key;
        if (is_file($path)) {
            unlink($path);
        }
    }
}
