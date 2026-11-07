<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * What caused an action; recorded in events and the deletion log.
 */
enum Trigger: string
{
    /** The retention period expired and retention:run processed the record. */
    case Schedule = 'schedule';

    /** An erasure request under Art. 17 GDPR. */
    case ErasureRequest = 'erasure_request';

    /** Application code, e.g. a restriction while the accuracy of data is contested. */
    case Manual = 'manual';
}
