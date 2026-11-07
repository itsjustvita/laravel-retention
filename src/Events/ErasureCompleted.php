<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Events;

use ItsJustVita\Retention\Erasure\ErasureReport;

/**
 * An erasure request was executed (not dispatched for dry runs).
 */
final readonly class ErasureCompleted
{
    public function __construct(
        public ErasureReport $report,
    ) {}
}
