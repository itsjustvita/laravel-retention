<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Policies;

use Closure;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Concerns\Restrictable;
use ItsJustVita\Retention\Database\RetentionColumns;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Exceptions\MissingRetentionPolicy;
use ItsJustVita\Retention\Exceptions\RetentionException;
use ItsJustVita\Retention\Execution\StartColumn;
use ItsJustVita\Retention\Execution\TemporalType;
use ItsJustVita\Retention\Log\DeletionLog;
use ItsJustVita\Retention\Models\RetentionHold;
use ItsJustVita\Retention\Models\RetentionLog;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Rules\RetentionRule;
use ItsJustVita\Retention\Rules\RuleCatalogue;
use ItsJustVita\Retention\Support\RelationResolver;
use Throwable;

/**
 * Checks a model's retention policy for completeness and against the rule
 * catalogue, the database schema and the model's relations.
 *
 * Runs in retention:audit, before retention:run/due/generate, and in the
 * app's tests via expect(Model::class)->toHaveValidRetentionPolicy().
 * Column checks need a migrated database.
 */
final readonly class PolicyValidator
{
    public function __construct(
        private PolicyRegistry $policies,
        private RuleCatalogue $rules,
        private StartColumn $startColumn,
        private DeletionLog $log,
    ) {}

    /**
     * @throws InvalidRetentionPolicy
     */
    public function validate(string $model): void
    {
        $violations = $this->violations($model);

        if ($violations !== []) {
            throw new InvalidRetentionPolicy($model, $violations);
        }
    }

    /**
     * Validates several models and the package environment at once.
     *
     * @param  list<string>  $models
     *
     * @throws InvalidRetentionPolicy with the violations of all models
     */
    public function assertValid(array $models): void
    {
        $violations = $this->environmentViolations();
        $invalid = $violations === [] ? [] : ['environment'];

        foreach ($models as $model) {
            foreach ($this->violations($model) as $violation) {
                $violations[] = "{$model}: {$violation}";
                $invalid[] = $model;
            }
        }

        if ($violations !== []) {
            throw new InvalidRetentionPolicy(implode(', ', array_values(array_unique($invalid))), $violations);
        }
    }

    /**
     * @return list<string>
     */
    public function violations(string $model): array
    {
        if (! $this->policies->has($model)) {
            return [MissingRetentionPolicy::for($model)->getMessage()];
        }

        try {
            $policy = $this->policies->for($model);
        } catch (Throwable $e) {
            return [$e instanceof RetentionException ? $e->getMessage() : 'retentionPolicy() threw '.$e::class.': '.$e->getMessage()];
        }

        $instance = new $model;

        $violations = [
            ...$this->completenessViolations($policy),
            ...$this->anonymizerViolations($instance, $policy),
            ...$this->restrictionViolations($instance, $policy),
            ...$this->relationViolations($instance, $policy),
            ...$this->columnViolations($instance, $policy),
        ];

        $cycle = $this->blockingCycle($model);

        if ($cycle !== null) {
            $violations[] = 'blockedBy() forms a cycle, so none of these records can ever be deleted: '.implode(' → ', $cycle).'.';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function completenessViolations(RetentionPolicy $policy): array
    {
        $violations = [];

        if ($policy->ruleKey === null) {
            $violations[] = 'no rule set; call rule().';
        } elseif (! $this->rules->has($policy->ruleKey)) {
            $violations[] = "unknown retention rule [{$policy->ruleKey}].";
        }

        if ($policy->startColumn === null) {
            $violations[] = 'no start column set; call startsAt().';
        }

        if ($policy->finalAction === null) {
            $violations[] = 'no action set; call action() with delete or anonymize.';
        }

        if ($policy->responsibleUnit === null) {
            $violations[] = 'no responsible unit set; call responsible().';
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function anonymizerViolations(Model $model, RetentionPolicy $policy): array
    {
        if ($policy->finalAction !== Action::Anonymize) {
            return [];
        }

        $anonymizer = $policy->anonymizer;

        return match (true) {
            $anonymizer === null => ['action anonymize requires an anonymizer; call anonymizeUsing().'],
            $anonymizer instanceof Closure => [],
            class_exists($anonymizer) => method_exists($anonymizer, '__invoke')
                ? []
                : ["anonymizer [{$anonymizer}] is not invokable; add an __invoke() method."],
            method_exists($model, $anonymizer) => [],
            default => ["anonymizer [{$anonymizer}] is neither an invokable class nor a method of the model."],
        };
    }

    /**
     * @return list<string>
     */
    private function relationViolations(Model $model, RetentionPolicy $policy): array
    {
        $violations = [];

        if ($policy->blockingRelations !== [] && $policy->finalAction === Action::Anonymize) {
            $violations[] = 'blockedBy() only applies to action delete; an anonymised record keeps its keys.';
        }

        if ($policy->blockingRelations !== [] && ! $this->usesRestrictable($model)) {
            $violations[] = 'blockedBy() requires the Restrictable trait, because an erasure request restricts a record while it is blocked.';
        }

        foreach ($policy->blockingRelations as $relation) {
            $violation = $this->relationViolation($model, $relation);

            if ($violation !== null) {
                $violations[] = "blockedBy(): {$violation}";
            }
        }

        foreach ($policy->holdRelations as $path) {
            $violation = $this->holdPathViolation($model, $path);

            if ($violation !== null) {
                $violations[] = "inheritsHoldsFrom(): {$violation}";
            }
        }

        return $violations;
    }

    /**
     * Follows a (dotted) relation path; the model at its end must be able to
     * carry legal holds.
     */
    private function holdPathViolation(Model $model, string $path): ?string
    {
        $current = $model;

        foreach (explode('.', $path) as $segment) {
            $violation = $this->relationViolation($current, $segment);

            if ($violation !== null) {
                return $violation;
            }

            $current = RelationResolver::resolve($current, $segment)->getRelated();
        }

        return $this->policies->has($current::class)
            ? null
            : "[{$path}] leads to [".$current::class.'], which does not use HasRetention and cannot carry legal holds.';
    }

    /**
     * Checks that the package's own tables exist.
     *
     * @return list<string>
     */
    public function environmentViolations(): array
    {
        $tables = [(new RetentionHold)->getTable() => new RetentionHold];

        if ($this->log->enabled()) {
            $tables[(new RetentionLog)->getTable()] = new RetentionLog;
        }

        $violations = [];

        if ($this->log->enabled() && ! $this->log->hasKey()) {
            $violations[] = 'the deletion log needs a key to pseudonymise record IDs; set RETENTION_LOG_KEY (or APP_KEY), or disable the log with retention.log.enabled.';
        }

        foreach ($tables as $table => $model) {
            if (! $model->getConnection()->getSchemaBuilder()->hasTable($table)) {
                $violations[] = "table [{$table}] does not exist; publish and run the package migrations (php artisan vendor:publish --tag=retention-migrations && php artisan migrate).";
            }
        }

        return $violations;
    }

    private function relationViolation(Model $model, string $relation): ?string
    {
        return RelationResolver::violation($model, $relation);
    }

    /**
     * @return list<string>
     */
    private function columnViolations(Model $model, RetentionPolicy $policy): array
    {
        $schema = $model->getConnection()->getSchemaBuilder();
        $table = $model->getTable();

        if (! $schema->hasTable($table)) {
            return ["table [{$table}] does not exist; run the migrations before validating."];
        }

        $columns = $schema->getColumnListing($table);
        $violations = [];

        if ($policy->startColumn !== null) {
            if (! in_array($policy->startColumn, $columns, true)) {
                $violations[] = "start column [{$policy->startColumn}] does not exist on table [{$table}].";
            } elseif (TemporalType::fromColumnType($type = $this->startColumn->columnTypeName($model, $policy->startColumn)) === null) {
                $violations[] = "start column [{$policy->startColumn}] has type [{$type}]; expected a date, datetime/timestamp or integer (Unix timestamp) column.";
            }
        }

        if ($policy->finalAction === Action::Anonymize && ! in_array(RetentionColumns::ANONYMIZED_AT, $columns, true)) {
            $violations[] = 'column ['.RetentionColumns::ANONYMIZED_AT."] does not exist on table [{$table}]; add it with \$table->anonymizable(), required by action anonymize.";
        }

        $rule = $this->rule($policy);

        if (! in_array(RetentionColumns::RESTRICTED_AT, $columns, true)) {
            $missing = 'column ['.RetentionColumns::RESTRICTED_AT."] does not exist on table [{$table}]; add it with \$table->restrictable()";

            if ($rule?->blocksErasure) {
                $violations[] = "{$missing}, required because rule [{$rule->key}] blocks erasure.";
            } elseif ($this->usesRestrictable($model)) {
                $violations[] = "{$missing}, required by the Restrictable trait.";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function restrictionViolations(Model $model, RetentionPolicy $policy): array
    {
        $rule = $this->rule($policy);

        if ($rule?->blocksErasure && ! $this->usesRestrictable($model)) {
            return ["rule [{$rule->key}] blocks erasure, so the model must use the Restrictable trait."];
        }

        return [];
    }

    private function rule(RetentionPolicy $policy): ?RetentionRule
    {
        return $policy->ruleKey !== null && $this->rules->has($policy->ruleKey)
            ? $this->rules->get($policy->ruleKey)
            : null;
    }

    private function usesRestrictable(Model $model): bool
    {
        return in_array(Restrictable::class, class_uses_recursive($model), true);
    }

    /**
     * Follows blockedBy() through the related models' policies and returns
     * the path back to the start model, if there is one.
     *
     * @param  class-string<Model>  $start
     * @return list<string>|null
     */
    private function blockingCycle(string $start): ?array
    {
        $seen = [];

        return $this->findCycle($start, $start, [], $seen);
    }

    /**
     * @param  class-string<Model>  $start
     * @param  class-string<Model>  $model
     * @param  list<string>  $path
     * @param  array<string, true>  $seen
     * @return list<string>|null
     */
    private function findCycle(string $start, string $model, array $path, array &$seen): ?array
    {
        $seen[$model] = true;

        try {
            $policy = $this->policies->for($model);
        } catch (RetentionException) {
            return null;
        }

        $instance = new $model;

        foreach ($policy->blockingRelations as $relation) {
            $related = $this->relatedModel($instance, $relation);

            if ($related === null) {
                continue;
            }

            $step = [...$path, class_basename($model).'.'.$relation];

            if ($related === $start) {
                return [...$step, class_basename($start)];
            }

            if (isset($seen[$related]) || ! $this->policies->has($related)) {
                continue;
            }

            $cycle = $this->findCycle($start, $related, $step, $seen);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * @return class-string<Model>|null
     */
    private function relatedModel(Model $model, string $relation): ?string
    {
        return RelationResolver::violation($model, $relation) === null
            ? RelationResolver::resolve($model, $relation)->getRelated()::class
            : null;
    }
}
