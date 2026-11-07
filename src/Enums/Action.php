<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * What happens to a record when its retention period expires.
 */
enum Action: string
{
    /** Hard delete (forceDelete() with SoftDeletes). */
    case Delete = 'delete';

    /** Remove the personal data via the policy's anonymizer and keep the row. */
    case Anonymize = 'anonymize';
}
