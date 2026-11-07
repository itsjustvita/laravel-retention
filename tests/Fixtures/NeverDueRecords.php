<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Execution\DueRecords;

/**
 * Simulates a disagreement between the SQL selection and the per-record check.
 */
readonly class NeverDueRecords extends DueRecords
{
    public function isDue(Model $record, CarbonInterface $now): bool
    {
        return false;
    }
}
