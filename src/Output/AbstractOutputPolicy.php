<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Output;

/**
 * Base implementation for task result output policies.
 *
 * Always includes "final" (the last run's own result); includes "steps"
 * (one entry per run record) only when the "verbose" option is enabled.
 *
 * @package rafalmasiarek\DashboardKitScheduler\Output
 */
abstract class AbstractOutputPolicy implements OutputPolicyInterface
{
    /**
     * {@inheritDoc}
     */
    public function buildResult(string $taskName, array $runs, array $options = []): array
    {
        $result = [
            'final' => $this->extractFinal($runs),
        ];

        if ($this->isEnabled($options, 'verbose')) {
            $result['steps'] = $this->buildSteps($runs);
        }

        return $result;
    }

    /**
     * Build the "steps" section: one normalized entry per run record.
     *
     * @param array<int,array<string,mixed>> $runs
     *
     * @return array<int,array<string,mixed>>
     */
    protected function buildSteps(array $runs): array
    {
        $steps = [];

        foreach ($runs as $i => $run) {
            if (!\is_array($run)) {
                continue;
            }

            $steps[$i] = $this->buildStep($run);
        }

        return $steps;
    }

    /**
     * Extract the "final" value from the last run record.
     *
     * @param array<int,array<string,mixed>> $runs
     *
     * @return mixed
     */
    protected function extractFinal(array $runs): mixed
    {
        $last = $runs !== [] ? $runs[\count($runs) - 1] : null;

        return \is_array($last) ? ($last['result'] ?? null) : null;
    }

    /**
     * Read a boolean-ish output option.
     *
     * Accepts native booleans, non-zero ints, and the strings "1"/"true"/"yes"/"on".
     *
     * @param array<string,mixed> $options
     * @param string $key
     *
     * @return bool
     */
    protected function isEnabled(array $options, string $key): bool
    {
        if (!\array_key_exists($key, $options)) {
            return false;
        }

        $value = $options[$key];

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value !== 0;
        }

        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Build the normalized payload for a single run record.
     *
     * @param array<string,mixed> $run
     *
     * @return array<string,mixed>
     */
    abstract protected function buildStep(array $run): array;
}
