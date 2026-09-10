<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Task;

/**
 * Action-to-factory registry.
 *
 * Maps action name strings (from module schedule definitions) to TaskFactoryInterface
 * implementations. Used by SchedulerAddon to resolve the right factory per task.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class TaskRegistry
{
    /**
     * @var array<string,TaskFactoryInterface>
     */
    private array $factories = [];

    /**
     * Register a factory for an action name.
     *
     * @param string               $action
     * @param TaskFactoryInterface $factory
     * @return void
     */
    public function register(string $action, TaskFactoryInterface $factory): void
    {
        $action = \trim($action);
        if ($action === '') {
            return;
        }

        $this->factories[$action] = $factory;
    }

    /**
     * Resolve the factory for an action name.
     *
     * @param string $action
     * @return TaskFactoryInterface|null
     */
    public function get(string $action): ?TaskFactoryInterface
    {
        $action = \trim($action);
        if ($action === '') {
            return null;
        }

        return $this->factories[$action] ?? null;
    }
}
