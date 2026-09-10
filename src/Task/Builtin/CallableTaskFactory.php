<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Task\Builtin;

use Psr\Container\ContainerInterface;
use rafalmasiarek\DashboardKitScheduler\Clock;
use rafalmasiarek\DashboardKitScheduler\Task\TaskFactoryInterface;
use rafalmasiarek\DashboardKitScheduler\TaskContext;

/**
 * Action: callable
 *
 * Invokes an arbitrary PHP callable (closure, "Class@method", "Class::method").
 * Target is read from $options['handler'] at runtime.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class CallableTaskFactory implements TaskFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function build(string $taskId, ContainerInterface $container, Clock $clock, array $options): callable
    {
        return function (TaskContext $context) use ($taskId, $container): mixed {
            /** @var array<string,mixed> $opt */
            $opt = \is_array($context->opt) ? $context->opt : [];

            $target = $opt['handler'] ?? null;

            $cb = self::resolveCallable($target);
            if (!\is_callable($cb)) {
                throw new \RuntimeException("Scheduler task '{$taskId}' has a non-callable handler.");
            }

            return $this->invokeCallableCompat($cb, $context, $container);
        };
    }

    /**
     * Resolve a callable from a mixed target value.
     *
     * Supports: Closure, "Class@method", "Class::method", or any PHP callable.
     *
     * @param mixed $target
     * @return callable|null
     */
    private static function resolveCallable(mixed $target): ?callable
    {
        if (\is_callable($target)) {
            return $target;
        }

        if (\is_string($target)) {
            if (\str_contains($target, '@') || \str_contains($target, '::')) {
                $sep = \str_contains($target, '@') ? '@' : '::';
                [$class, $method] = \explode($sep, $target, 2);
                if (\class_exists($class) && \method_exists($class, $method)) {
                    return [$class, $method];
                }
            }
        }

        return null;
    }

    /**
     * Invoke callable preserving the Scheduler compatibility contract.
     *
     * Passes TaskContext + ContainerInterface when the callable accepts >= 2 parameters,
     * TaskContext only when it accepts 1, or no args when it accepts 0.
     *
     * @param callable           $cb
     * @param TaskContext        $context
     * @param ContainerInterface $container
     * @return mixed
     */
    private function invokeCallableCompat(callable $cb, TaskContext $context, ContainerInterface $container): mixed
    {
        try {
            if (\is_array($cb) && isset($cb[0], $cb[1])) {
                $ref = new \ReflectionMethod($cb[0], (string) $cb[1]);
                $n   = $ref->getNumberOfParameters();
                return $n >= 2 ? $cb($context, $container) : ($n === 1 ? $cb($context) : $cb());
            }

            if (\is_string($cb) && \str_contains($cb, '::')) {
                $ref = new \ReflectionMethod($cb);
                $n   = $ref->getNumberOfParameters();
                return $n >= 2 ? $cb($context, $container) : ($n === 1 ? $cb($context) : $cb());
            }

            $ref = new \ReflectionFunction(\Closure::fromCallable($cb));
            $n   = $ref->getNumberOfParameters();
            return $n >= 2 ? $cb($context, $container) : ($n === 1 ? $cb($context) : $cb());
        } catch (\Throwable) {
            return $cb($context, $container);
        }
    }
}
