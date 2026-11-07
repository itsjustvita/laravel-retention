<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Events;

use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Policies\TablePolicy;

/**
 * A batch of rows was deleted from a table with a table policy.
 */
final readonly class TableRowsDeleted
{
    public function __construct(
        public TablePolicy $policy,
        public int $count,
        public Trigger $trigger,
    ) {}
}
