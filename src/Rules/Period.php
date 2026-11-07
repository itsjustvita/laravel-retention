<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Rules;

use Carbon\CarbonImmutable;
use DateInterval;
use Exception;
use InvalidArgumentException;

/**
 * A retention period, given as an ISO 8601 duration.
 *
 * A period is either date-based (years, months, weeks, days), counted in
 * calendar days, or time-based (hours, minutes, seconds), counted exactly.
 * Mixing both is not allowed, because the two count differently.
 */
final readonly class Period
{
    private function __construct(
        public string $iso,
        public int $months,
        public int $days,
        public int $seconds,
    ) {}

    public static function parse(string $iso): self
    {
        try {
            $interval = new DateInterval($iso);
        } catch (Exception $e) {
            throw new InvalidArgumentException(
                "Invalid ISO 8601 duration [{$iso}]; expected e.g. P8Y, P6M, P7D or PT72H.",
                previous: $e,
            );
        }

        $months = $interval->y * 12 + $interval->m;
        $days = $interval->d;
        $seconds = $interval->h * 3600 + $interval->i * 60 + $interval->s;

        if ($seconds > 0 && ($months > 0 || $days > 0)) {
            throw new InvalidArgumentException(
                "Duration [{$iso}] mixes date and time components; use either a date-based (e.g. P7D) or a time-based (e.g. PT36H) duration.",
            );
        }

        return new self($iso, $months, $days, $seconds);
    }

    public function isTimeBased(): bool
    {
        return $this->seconds > 0;
    }

    /**
     * Adds the period. A month-end day that does not exist in the target
     * month falls back to the last day of that month (§ 188 (3) BGB).
     */
    public function addTo(CarbonImmutable $date): CarbonImmutable
    {
        return $date->addMonthsNoOverflow($this->months)->addDays($this->days)->addSeconds($this->seconds);
    }

    public function subtractFrom(CarbonImmutable $date): CarbonImmutable
    {
        return $date->subMonthsNoOverflow($this->months)->subDays($this->days)->subSeconds($this->seconds);
    }
}
