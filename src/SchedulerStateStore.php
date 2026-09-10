<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * Interface for storing scheduler state (last run timestamps, etc.).
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
interface SchedulerStateStore
{
    /**
     * Read a value by key.
     *
     * @param string $key
     * @return string|null
     */
    public function read(string $key): ?string;

    /**
     * Write a value by key.
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    public function write(string $key, string $value): void;

    /**
     * Delete a value by key.
     *
     * @param string $key
     * @return void
     */
    public function delete(string $key): void;
}
