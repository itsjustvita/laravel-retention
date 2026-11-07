<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * Result of applying a record's final action.
 */
enum ActionOutcome: string
{
    case Deleted = 'deleted';

    case Anonymized = 'anonymized';

    /** The record had already been anonymised; nothing was changed. */
    case AlreadyAnonymized = 'already_anonymized';

    /** blockedBy() relations still hold records; nothing was changed. */
    case Blocked = 'blocked';

    /** A model event listener cancelled the deletion. */
    case Cancelled = 'cancelled';
}
