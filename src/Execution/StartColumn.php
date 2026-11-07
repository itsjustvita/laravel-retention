<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Execution;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Calculation\DueConstraint;

/**
 * Translates between start columns in the database and points in time:
 * the SQL condition for a DueConstraint, and the start event of a loaded
 * record.
 *
 * The SQL condition may round towards selecting fewer records (e.g. when a
 * cutoff has fractions of a second the column cannot store), never more.
 */
final class StartColumn
{
    /** @var array<string, TemporalType|null> */
    private array $types = [];

    public function __construct(
        private readonly Repository $config,
    ) {}

    public function type(Model $model, string $column): ?TemporalType
    {
        $key = $model->getConnectionName().'|'.$model->getTable().'|'.$column;

        if (! array_key_exists($key, $this->types)) {
            $this->types[$key] = TemporalType::fromColumnType($this->columnTypeName($model, $column));
        }

        return $this->types[$key];
    }

    public function columnTypeName(Model $model, string $column): string
    {
        return $model->getConnection()->getSchemaBuilder()->getColumnType($model->getTable(), $column);
    }

    /**
     * Adds `start column before the cutoff` to the query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function constrain(Builder $query, string $column, TemporalType $type, DueConstraint $constraint): void
    {
        $qualified = $query->getModel()->qualifyColumn($column);
        $cutoff = $constraint->cutoff;
        $operator = $constraint->inclusive ? '<=' : '<';

        match ($type) {
            // A date d satisfies the constraint if its midnight does. Comparing with `<` against a
            // date also works where SQLite keeps "Y-m-d H:i:s" strings in date columns.
            TemporalType::Date => $query->where($qualified, '<', ! $constraint->inclusive && $cutoff->eq($cutoff->startOfDay())
                ? $cutoff->toDateString()
                : $cutoff->addDay()->toDateString()),
            TemporalType::DateTime => $query->where($qualified, $operator, $cutoff
                ->setTimezone($this->applicationTimezone())
                ->format($query->getModel()->getDateFormat())),
            TemporalType::DateTimeWithZone => $query->where($qualified, $operator, $cutoff->utc()->format('Y-m-d H:i:sP')),
            TemporalType::UnixTimestamp => $query->where($qualified, $operator, $cutoff->getTimestamp()),
        };
    }

    /**
     * The start event stored on a record, as last loaded from or saved to the
     * database; null while no period is running.
     */
    public function value(Model $record, string $column, TemporalType $type, string $retentionTimezone): ?CarbonImmutable
    {
        $raw = $record->getRawOriginal($column);

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof DateTimeInterface) {
            return CarbonImmutable::instance($raw);
        }

        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return null;
        }

        return match ($type) {
            TemporalType::Date => CarbonImmutable::parse(substr((string) $raw, 0, 10), $retentionTimezone)->startOfDay(),
            TemporalType::DateTime => CarbonImmutable::parse((string) $raw, $this->applicationTimezone()),
            TemporalType::DateTimeWithZone => CarbonImmutable::parse((string) $raw, 'UTC'),
            TemporalType::UnixTimestamp => CarbonImmutable::createFromTimestamp((int) $raw, 'UTC'),
        };
    }

    private function applicationTimezone(): string
    {
        $timezone = $this->config->get('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
