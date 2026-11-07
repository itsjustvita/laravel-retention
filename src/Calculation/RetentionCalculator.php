<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Calculation;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use ItsJustVita\Retention\Enums\Counting;
use ItsJustVita\Retention\Rules\Period;
use ItsJustVita\Retention\Rules\RetentionRule;
use LogicException;

/**
 * Calculates when retention periods expire, in both directions: the expiry of
 * a single start event, and the inverse constraint that selects every start
 * event that is due at a given evaluation date.
 *
 * Counting rules:
 *  - end_of_year: the period starts at the end of the calendar year of the
 *    start event (§ 147 (4) AO, § 257 (5) HGB, § 199 (1) BGB).
 *  - event, date-based period: counted in calendar days from the day after the
 *    start event and ends at the end of the corresponding day (§§ 187 (1),
 *    188 (2), (3) BGB).
 *  - event, time-based period: exact.
 *
 * A record is deletable from the returned instant on. All calendar
 * calculations use the configured retention timezone.
 *
 * Rule versions are chosen by evaluation date: from `validFrom` on, the
 * current version applies, except to records that had already expired under
 * the previous version by then.
 */
final readonly class RetentionCalculator
{
    private DateTimeZone $zone;

    public function __construct(string $timezone)
    {
        try {
            $this->zone = new DateTimeZone($timezone);
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid retention timezone [{$timezone}].", previous: $e);
        }
    }

    public function timezone(): string
    {
        return $this->zone->getName();
    }

    /**
     * The instant from which a record with the given start event is
     * deletable, according to the rule version in force at `$now`.
     */
    public function expiresAt(DateTimeInterface $event, RetentionRule $rule, ?DateTimeInterface $now = null): CarbonImmutable
    {
        return $this->expiry($this->local($event), $rule, $this->now($now));
    }

    public function isDue(DateTimeInterface $event, RetentionRule $rule, ?DateTimeInterface $now = null): bool
    {
        $now = $this->now($now);

        return $this->expiry($this->local($event), $rule, $now)->lte($now);
    }

    /**
     * The constraint on the start column that selects exactly the records
     * that are due at `$now`, i.e. those for which isDue() is true.
     */
    public function dueConstraint(RetentionRule $rule, ?DateTimeInterface $now = null): DueConstraint
    {
        return $this->constraint($rule, $this->now($now));
    }

    private function expiry(CarbonImmutable $event, RetentionRule $rule, CarbonImmutable $now): CarbonImmutable
    {
        if ($rule->previous === null) {
            return $this->plainExpiry($event, $rule);
        }

        $validFrom = $this->validFrom($rule);

        if ($now->lt($validFrom)) {
            return $this->expiry($event, $rule->previous, $now);
        }

        $expiryBefore = $this->expiry($event, $rule->previous, $validFrom);

        if ($expiryBefore->lte($validFrom)) {
            return $expiryBefore;
        }

        return $this->plainExpiry($event, $rule)->max($validFrom);
    }

    private function constraint(RetentionRule $rule, CarbonImmutable $now): DueConstraint
    {
        if ($rule->previous === null) {
            return $this->plainConstraint($rule, $now);
        }

        $validFrom = $this->validFrom($rule);

        if ($now->lt($validFrom)) {
            return $this->constraint($rule->previous, $now);
        }

        return $this->constraint($rule->previous, $validFrom)
            ->union($this->plainConstraint($rule, $now));
    }

    /**
     * Expiry under a single rule version, ignoring transitions.
     */
    private function plainExpiry(CarbonImmutable $event, RetentionRule $rule): CarbonImmutable
    {
        $period = $rule->period;

        if ($period->isTimeBased()) {
            return $event->utc()->addSeconds($period->seconds)->setTimezone($this->zone);
        }

        if ($rule->counting === Counting::EndOfYear) {
            return $this->expiryForYear($event->year, $period);
        }

        return $this->expiryForDay($event, $period);
    }

    /**
     * Inverse of plainExpiry(): finds the latest year or day whose expiry is
     * not after `$now`. Month-end clamping makes the period arithmetic
     * non-invertible, so the first guess can be off by a few days; the loops
     * walk to the exact answer from any guess, because expiry never decreases
     * with a later year or day.
     */
    private function plainConstraint(RetentionRule $rule, CarbonImmutable $now): DueConstraint
    {
        $period = $rule->period;

        if ($period->isTimeBased()) {
            return new DueConstraint($now->utc()->subSeconds($period->seconds)->setTimezone($this->zone), inclusive: true);
        }

        if ($rule->counting === Counting::EndOfYear) {
            $year = $period->subtractFrom($now)->year - 1;

            while ($this->expiryForYear($year + 1, $period)->lte($now)) {
                $year++;
            }

            while ($this->expiryForYear($year, $period)->gt($now)) {
                $year--;
            }

            return new DueConstraint($this->startOfYear($year + 1), inclusive: false);
        }

        $day = $period->subtractFrom($now->startOfDay())->subDay()->startOfDay();

        while ($this->expiryForDay($day->addDay(), $period)->lte($now)) {
            $day = $day->addDay()->startOfDay();
        }

        while ($this->expiryForDay($day, $period)->gt($now)) {
            $day = $day->subDay()->startOfDay();
        }

        return new DueConstraint($day->addDay()->startOfDay(), inclusive: false);
    }

    private function expiryForYear(int $year, Period $period): CarbonImmutable
    {
        return $period->addTo($this->startOfYear($year + 1))->startOfDay();
    }

    private function expiryForDay(CarbonImmutable $day, Period $period): CarbonImmutable
    {
        return $period->addTo($day->startOfDay())->addDay()->startOfDay();
    }

    private function startOfYear(int $year): CarbonImmutable
    {
        return CarbonImmutable::createMidnightDate($year, 1, 1, $this->zone)->startOfDay();
    }

    private function validFrom(RetentionRule $rule): CarbonImmutable
    {
        if ($rule->validFrom === null) {
            throw new LogicException("Retention rule [{$rule->key}] has a previous version but no valid_from date.");
        }

        return CarbonImmutable::parse($rule->validFrom, $this->zone)->startOfDay();
    }

    private function local(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->zone);
    }

    private function now(?DateTimeInterface $now): CarbonImmutable
    {
        return $this->local($now ?? CarbonImmutable::now());
    }
}
