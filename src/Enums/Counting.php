<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * How a retention period is counted from its start event.
 */
enum Counting: string
{
    /**
     * The period starts at the end of the calendar year in which the start
     * event occurred (§ 147 (4) AO, § 257 (5) HGB, § 199 (1) BGB).
     */
    case EndOfYear = 'end_of_year';

    /**
     * The period starts with the start event itself.
     */
    case Event = 'event';
}
