<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Exception;

/**
 * Thrown when a task's file lock cannot be acquired.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class TaskLockedException extends \RuntimeException {}
