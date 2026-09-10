<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler;

/**
 * Timezone-aware time utility for all scheduler operations.
 *
 * Timezone resolution order:
 *  1) $configuredTzId (passed at construction)
 *  2) PHP default timezone (php.ini / date_default_timezone_set)
 *  3) $fallbackTzId (default: UTC)
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class Clock
{
    /**
     * @var string
     */
    private string $configuredTzId;

    /**
     * @var string
     */
    private string $fallbackTzId;

    /**
     * @var array<string, \DateTimeZone>
     */
    private array $cache = [];

    /**
     * @param string $configuredTzId Timezone ID to prefer (e.g. "Europe/Warsaw").
     * @param string $fallbackTzId   Fallback when configured and PHP defaults are invalid.
     */
    public function __construct(string $configuredTzId = '', string $fallbackTzId = 'UTC')
    {
        $this->configuredTzId = \trim($configuredTzId);
        $this->fallbackTzId   = \trim($fallbackTzId) !== '' ? \trim($fallbackTzId) : 'UTC';
    }

    /**
     * Resolve the effective timezone ID.
     *
     * @return string
     */
    public function tzId(): string
    {
        foreach ([$this->configuredTzId, (string)\date_default_timezone_get(), $this->fallbackTzId] as $id) {
            $id = \trim($id);
            if ($id === '') {
                continue;
            }
            try {
                new \DateTimeZone($id);
                return $id;
            } catch (\Throwable) {
                // try next candidate
            }
        }
        return 'UTC';
    }

    /**
     * Return effective DateTimeZone instance.
     *
     * @return \DateTimeZone
     */
    public function tz(): \DateTimeZone
    {
        $id = $this->tzId();
        return $this->cache[$id] ??= new \DateTimeZone($id);
    }

    /**
     * Return current time in Clock timezone.
     *
     * @return \DateTimeImmutable
     */
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->tz());
    }

    /**
     * Return current time in UTC.
     *
     * @return \DateTimeImmutable
     */
    public function nowUtc(): \DateTimeImmutable
    {
        return $this->now()->setTimezone($this->utcTz());
    }

    /**
     * Create a DateTimeImmutable for the given time string in Clock timezone.
     *
     * @param string $timeStr
     * @return \DateTimeImmutable
     */
    public function at(string $timeStr): \DateTimeImmutable
    {
        return new \DateTimeImmutable($timeStr, $this->tz());
    }

    /**
     * Convert a DateTimeInterface into an immutable instance in Clock timezone.
     *
     * @param \DateTimeInterface $dt
     * @return \DateTimeImmutable
     */
    public function fromInterface(\DateTimeInterface $dt): \DateTimeImmutable
    {
        return $this->at('@' . $dt->getTimestamp())->setTimezone($this->tz());
    }

    /**
     * Format a timestamp as DATE_ATOM in Clock timezone.
     *
     * @param int $ts Unix timestamp.
     * @return string
     */
    public function formatAtomFromTimestamp(int $ts): string
    {
        return $this->at('@' . $ts)->setTimezone($this->tz())->format(\DATE_ATOM);
    }

    /**
     * Format a DateTimeInterface as DATE_ATOM in Clock timezone.
     *
     * @param \DateTimeInterface $dt
     * @return string
     */
    public function formatAtom(\DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->setTimezone($this->tz())->format(\DATE_ATOM);
    }

    /**
     * Format a DateTimeInterface for SQL DATETIME in UTC: "Y-m-d H:i:s".
     *
     * @param \DateTimeInterface $dt
     * @return string
     */
    public function formatSqlUtc(\DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->setTimezone($this->utcTz())->format('Y-m-d H:i:s');
    }

    /**
     * Minute slot key (YmdHi) for a given DateTimeInterface in Clock timezone.
     *
     * @param \DateTimeInterface $dt
     * @return string
     */
    public function formatMinuteKey(\DateTimeInterface $dt): string
    {
        return $this->fromInterface($dt)->setTimezone($this->tz())->format('YmdHi');
    }

    /**
     * Minute slot key (YmdHi) for a given timestamp in Clock timezone.
     *
     * @param int $ts Unix timestamp.
     * @return string
     */
    public function formatMinuteKeyFromTimestamp(int $ts): string
    {
        return $this->formatMinuteKey($this->at('@' . $ts));
    }

    /**
     * Return cached UTC timezone instance.
     *
     * @return \DateTimeZone
     */
    private function utcTz(): \DateTimeZone
    {
        return $this->cache['UTC'] ??= new \DateTimeZone('UTC');
    }
}
