<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Events;

use ItsJustVita\Retention\Execution\RunReport;

/**
 * retention:run finished (also dispatched for dry runs).
 */
final readonly class RunCompleted
{
    public function __construct(
        public RunReport $report,
    ) {}
}
