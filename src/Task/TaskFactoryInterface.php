<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Task;

use Psr\Container\ContainerInterface;
use rafalmasiarek\DashboardKitScheduler\Clock;

/**
 * Builds a task callback from a task definition.
 *
 * The returned callable is compatible with Scheduler callback invocation rules:
 * - It may accept a single TaskContext parameter.
 * - It may accept no parameters (legacy compatibility).
 *
 * Factories must not concern themselves with scheduler internals (locking, due logic, state).
 * Their responsibility is translating config/options into executable task logic.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
interface TaskFactoryInterface
{
    /**
     * Build a task callback.
     *
     * @param string               $taskId    Task identifier.
     * @param ContainerInterface   $container PSR-11 container for service resolution.
     * @param Clock                $clock     Shared clock for task runtime.
     * @param array<string,mixed>  $options   Task options from the module schedule definition.
     * @return callable
     */
    public function build(string $taskId, ContainerInterface $container, Clock $clock, array $options): callable;
}
