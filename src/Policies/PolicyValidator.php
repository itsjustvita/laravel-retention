<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Policies;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ItsJustVita\Retention\Concerns\Restrictable;
use ItsJustVita\Retention\Database\RetentionColumns;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Exceptions\MissingRetentionPolicy;
use ItsJustVita\Retention\Exceptions\RetentionException;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Rules\RetentionRule;
use ItsJustVita\Retention\Rules\RuleCatalogue;
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
     * @return list<string>
     */
    public function violations(string $model): array
    {
        if (! $this->policies->has($model)) {
            return [MissingRetentionPolicy::for($model)->getMessage()];
        }

        try {
            $policy = $this->policies->for($model);
        } catch (RetentionException $e) {
            return [$e->getMessage()];
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

        foreach (['blockedBy' => $policy->blockingRelations, 'inheritsHoldsFrom' => $policy->holdRelations] as $method => $relations) {
            foreach ($relations as $relation) {
                $violation = $this->relationViolation($model, $relation);

                if ($violation !== null) {
                    $violations[] = "{$method}(): {$violation}";
                }
            }
        }

        return $violations;
    }

    private function relationViolation(Model $model, string $relation): ?string
    {
        if (! method_exists($model, $relation)) {
            return "relation [{$relation}] does not exist on the model.";
        }

        try {
            $result = $model->{$relation}();
        } catch (Throwable $e) {
            return "relation [{$relation}] could not be resolved: {$e->getMessage()}";
        }

        return $result instanceof Relation ? null : "[{$relation}] is not an Eloquent relation.";
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

        if ($policy->startColumn !== null && ! in_array($policy->startColumn, $columns, true)) {
            $violations[] = "start column [{$policy->startColumn}] does not exist on table [{$table}].";
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
        if (! method_exists($model, $relation)) {
            return null;
        }

        try {
            $result = $model->{$relation}();
        } catch (Throwable) {
            return null;
        }

        return $result instanceof Relation ? $result->getRelated()::class : null;
    }
}
