<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * What an erasure request did with a record.
 */
enum ErasureStatus: string
{
    case Deleted = 'deleted';

    case Anonymized = 'anonymized';

    /** Restricted until its retention period expires or its dependent records are gone. */
    case Restricted = 'restricted';

    /** Kept because of a legal hold, its own or an inherited one. */
    case OnHold = 'on_hold';

    /** Left untouched: the start event has not occurred (e.g. the contract is still running). */
    case StartEventPending = 'start_event_pending';

    /** A model event listener cancelled the deletion. */
    case Cancelled = 'cancelled';

    /** The model has no retention policy (dry runs only; execute() refuses). */
    case NoPolicy = 'no_policy';
}
