<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Policies;

use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;

/**
 * The retention declaration of a table without a model, from
 * config('retention.tables'), e.g. sessions or failed_jobs.
 *
 * Rows are always deleted, through the query builder: no model events, no
 * legal holds and no per-row log entries.
 */
final readonly class TablePolicy
{
    private const array KEYS = ['category', 'label', 'description', 'rule', 'starts_at', 'start_event', 'responsible', 'connection'];

    public function __construct(
        public string $table,
        public string $category,
        public string $label,
        public string $ruleKey,
        public string $startColumn,
        public StartEvent $startEvent,
        public string $responsibleUnit,
        public ?string $description = null,
        public ?string $connection = null,
    ) {}

    /**
     * @param  array<mixed>  $definition
     *
     * @throws InvalidRetentionPolicy
     */
    public static function fromArray(string $table, array $definition): self
    {
        $unknown = array_diff(array_map(strval(...), array_keys($definition)), self::KEYS);

        if ($unknown !== []) {
            throw self::invalid($table, 'unknown keys ['.implode(', ', $unknown).'].');
        }

        $startEvent = $definition['start_event'] ?? null;

        return new self(
            table: $table,
            category: self::required($table, $definition, 'category'),
            label: self::required($table, $definition, 'label'),
            ruleKey: self::required($table, $definition, 'rule'),
            startColumn: self::required($table, $definition, 'starts_at'),
            startEvent: (is_string($startEvent) ? StartEvent::tryFrom($startEvent) : null)
                ?? throw self::invalid($table, '[start_event] must be one of ['.implode(', ', array_column(StartEvent::cases(), 'value')).'].'),
            responsibleUnit: self::required($table, $definition, 'responsible'),
            description: self::optional($table, $definition, 'description'),
            connection: self::optional($table, $definition, 'connection'),
        );
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function required(string $table, array $definition, string $key): string
    {
        $value = $definition[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw self::invalid($table, "[{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function optional(string $table, array $definition, string $key): ?string
    {
        $value = $definition[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw self::invalid($table, "[{$key}] must be a string.");
        }

        return $value;
    }

    private static function invalid(string $table, string $reason): InvalidRetentionPolicy
    {
        return new InvalidRetentionPolicy("table {$table}", [$reason]);
    }
}
