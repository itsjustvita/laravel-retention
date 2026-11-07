<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Document;

use Carbon\CarbonImmutable;
use ItsJustVita\Retention\Enums\Counting;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Rules\Period;

/**
 * Everything the deletion concept templates need; all lists are in a stable
 * order, so the same declarations produce the same document.
 */
final readonly class DeletionConcept
{
    /**
     * @param  list<ConceptEntry>  $entries
     * @param  array<string, string>  $excludedModels
     * @param  array<string, string>  $excludedTables
     * @param  string|array<mixed>|null  $purpose
     * @param  string|array<mixed>|null  $systemsNotCovered
     */
    public function __construct(
        public ?string $organisation,
        public ?string $controller,
        public ?string $dataProtectionOfficer,
        public ?string $version,
        public CarbonImmutable $asOf,
        public ?string $commit,
        public array $entries,
        public array $excludedModels,
        public array $excludedTables,
        public string $timezone,
        public bool $logEnabled,
        public Period $logRetention,
        public Period $holdsRetention,
        public string|array|null $purpose,
        public string|array|null $systemsNotCovered,
    ) {}

    /**
     * The deletion class matrix: one row per period (ascending), one cell per
     * start event, listing the data categories.
     *
     * @return list<array{period: Period, cells: array<string, list<array{label: string, endOfYear: bool}>>}>
     */
    public function matrix(): array
    {
        $rows = [];

        foreach ($this->entries as $entry) {
            $period = $entry->rule->period;
            $rows[$period->iso] ??= ['period' => $period, 'cells' => array_fill_keys(array_column(StartEvent::cases(), 'value'), [])];
            $rows[$period->iso]['cells'][$entry->startEvent->value][$entry->label.'|'.$entry->rule->counting->value] = [
                'label' => $entry->label,
                'endOfYear' => $entry->rule->counting === Counting::EndOfYear,
            ];
        }

        uasort($rows, fn (array $a, array $b): int => [$a['period']->approximateSeconds(), $a['period']->iso] <=> [$b['period']->approximateSeconds(), $b['period']->iso]);

        return array_values(array_map(function (array $row): array {
            foreach ($row['cells'] as $event => $cell) {
                ksort($cell);
                $row['cells'][$event] = array_values($cell);
            }

            return $row;
        }, $rows));
    }

    public function usesEndOfYearCounting(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->rule->counting === Counting::EndOfYear) {
                return true;
            }
        }

        return false;
    }
}
