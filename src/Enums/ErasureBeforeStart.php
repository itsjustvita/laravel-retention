<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * How an erasure request treats a record whose start event has not occurred
 * yet (start column is null, e.g. the contract is still running).
 */
enum ErasureBeforeStart: string
{
    /** Leave the record untouched and report it; the controller decides. */
    case Skip = 'skip';

    /** Apply the final action right away, e.g. for draft invoices. */
    case Apply = 'apply';
}
