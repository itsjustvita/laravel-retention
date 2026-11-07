<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Calculation;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The set of start events whose retention period has expired at a given
 * evaluation date: all start events before the cutoff (or at it, if
 * inclusive). Because expiry never decreases with a later start event, this
 * set is always such a prefix, which lets callers select due records with a
 * single `start_column < cutoff` condition.
 */
final readonly class DueConstraint
{
    public function __construct(
        public CarbonImmutable $cutoff,
        public bool $inclusive,
    ) {}

    public function isSatisfiedBy(DateTimeInterface $event): bool
    {
        $event = CarbonImmutable::instance($event);

        return $this->inclusive ? $event->lte($this->cutoff) : $event->lt($this->cutoff);
    }

    /**
     * Start events that satisfy either constraint. The union of two prefixes
     * is the larger one.
     */
    public function union(self $other): self
    {
        if ($this->cutoff->eq($other->cutoff)) {
            return new self($this->cutoff, $this->inclusive || $other->inclusive);
        }

        return $this->cutoff->gt($other->cutoff) ? $this : $other;
    }
}
