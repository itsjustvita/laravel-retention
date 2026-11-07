<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Execution;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Actions\Dependencies;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use ItsJustVita\Retention\Database\RetentionColumns;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Rules\RetentionRule;
use ItsJustVita\Retention\Rules\RuleCatalogue;

/**
 * Selects the records whose retention period has expired.
 *
 * Retention applies to every row of a table, so the selection ignores all
 * global scopes: restricted, soft-deleted and otherwise scoped-out records
 * are included. Records that are already anonymised are excluded.
 */
readonly class DueRecords
{
    public function __construct(
        private PolicyRegistry $policies,
        private RuleCatalogue $rules,
        private RetentionCalculator $calculator,
        private Dependencies $dependencies,
        private StartColumn $startColumn,
    ) {}

    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    public function query(string $model, CarbonInterface $now, bool $excludeBlocked = true): Builder
    {
        $policy = $this->policies->for($model);
        $instance = new $model;
        $column = $this->startColumnName($model, $policy);

        $query = $instance->newQueryWithoutScopes()->whereNotNull($instance->qualifyColumn($column));

        $this->startColumn->constrain(
            $query,
            $column,
            $this->temporalType($instance, $column),
            $this->calculator->dueConstraint($this->rule($model, $policy), $now),
        );

        if ($policy->finalAction === Action::Anonymize) {
            $query->whereNull($instance->qualifyColumn(RetentionColumns::ANONYMIZED_AT));
        }

        if ($excludeBlocked) {
            $this->dependencies->excludeBlocked($query, $policy);
        }

        return $query;
    }

    /**
     * Checks a single loaded record in PHP, independently of the SQL selection.
     */
    public function isDue(Model $record, CarbonInterface $now): bool
    {
        $event = $this->startEvent($record);

        return $event !== null
            && $this->calculator->isDue($event, $this->rule($record::class, $this->policies->for($record::class)), $now);
    }

    public function startEvent(Model $record): ?CarbonImmutable
    {
        $column = $this->startColumnName($record::class, $this->policies->for($record::class));

        return $this->startColumn->value($record, $column, $this->temporalType($record, $column), $this->calculator->timezone());
    }

    private function startColumnName(string $model, RetentionPolicy $policy): string
    {
        return $policy->startColumn
            ?? throw new InvalidRetentionPolicy($model, ['no start column set; call startsAt().']);
    }

    private function rule(string $model, RetentionPolicy $policy): RetentionRule
    {
        if ($policy->ruleKey === null) {
            throw new InvalidRetentionPolicy($model, ['no rule set; call rule().']);
        }

        return $this->rules->get($policy->ruleKey);
    }

    private function temporalType(Model $model, string $column): TemporalType
    {
        return $this->startColumn->type($model, $column)
            ?? throw new InvalidRetentionPolicy($model::class, ["start column [{$column}] is not a date, datetime/timestamp or integer column."]);
    }
}
