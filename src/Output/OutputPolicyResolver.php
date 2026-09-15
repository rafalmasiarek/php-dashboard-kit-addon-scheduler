<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Output;

/**
 * Resolves the output policy for a given task name.
 *
 * Falls back to DefaultOutputPolicy for any task without a registered
 * task-specific policy.
 *
 * @package rafalmasiarek\DashboardKitScheduler\Output
 */
final class OutputPolicyResolver
{
    /**
     * @var array<string, class-string<OutputPolicyInterface>>
     */
    private static array $policies = [];

    /**
     * Register a task-specific output policy class.
     *
     * @param string $taskName Full task name (e.g. "my_module.my_task").
     * @param class-string<OutputPolicyInterface> $policyClass
     *
     * @return void
     */
    public static function register(string $taskName, string $policyClass): void
    {
        self::$policies[$taskName] = $policyClass;
    }

    /**
     * Resolve the output policy instance for a task.
     *
     * @param string $taskName
     *
     * @return OutputPolicyInterface
     */
    public static function for(string $taskName): OutputPolicyInterface
    {
        $class = self::$policies[$taskName] ?? DefaultOutputPolicy::class;

        return new $class();
    }
}
