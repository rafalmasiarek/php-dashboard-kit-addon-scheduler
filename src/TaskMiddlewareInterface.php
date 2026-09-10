<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * Middleware pipeline for task execution.
 *
 * Implementations must call $next() to continue execution.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
interface TaskMiddlewareInterface
{
    /**
     * Handle task execution step.
     *
     * @param string              $taskName
     * @param array<string,mixed> $context
     * @param callable():mixed    $next
     * @return mixed
     */
    public function handle(string $taskName, array $context, callable $next): mixed;
}
