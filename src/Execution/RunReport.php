<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Execution;

use Carbon\CarbonImmutable;

/**
 * Result of a retention:run, in processing order.
 */
final readonly class RunReport
{
    /**
     * @param  list<ModelRunResult>  $results
     */
    public function __construct(
        public array $results,
        public bool $dryRun,
        public CarbonImmutable $evaluatedAt,
        public int $purgedHolds = 0,
        public int $purgedLogEntries = 0,
    ) {}

    /**
     * @param  'due'|'deleted'|'anonymized'|'blocked'|'onHold'|'cancelled'|'skipped'  $field
     */
    public function total(string $field): int
    {
        return array_sum(array_map(fn (ModelRunResult $result): int => $result->{$field}, $this->results));
    }
}
