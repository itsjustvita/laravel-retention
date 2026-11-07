<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Support\RelationResolver;

/**
 * Dependencies between models declared with blockedBy(): a record may only
 * be deleted once these relations hold no records at all, including
 * restricted and soft-deleted ones (all global scopes are ignored).
 */
final readonly class Dependencies
{
    public function __construct(
        private PolicyRegistry $policies,
    ) {}

    public function blocksDeletion(Model $record, RetentionPolicy $policy): bool
    {
        foreach ($policy->blockingRelations as $relation) {
            if (RelationResolver::resolve($record, $relation)->withoutGlobalScopes()->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restricts a query to records whose blockedBy() relations are empty.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function excludeBlocked(Builder $query, RetentionPolicy $policy): Builder
    {
        foreach ($policy->blockingRelations as $relation) {
            $query->whereDoesntHave($relation, fn (Builder $related) => $related->withoutGlobalScopes());
        }

        return $query;
    }

    /**
     * Sorts models so that every model comes after the models its
     * blockedBy() relations point to; otherwise the input order is kept.
     *
     * @param  list<class-string<Model>>  $models
     * @return list<class-string<Model>>
     *
     * @throws InvalidRetentionPolicy if blockedBy() relations form a cycle
     */
    public function processingOrder(array $models): array
    {
        $prerequisites = [];

        foreach ($models as $model) {
            $prerequisites[$model] = array_values(array_intersect($this->blockingModels($model), $models));
        }

        $ordered = [];

        while (count($ordered) < count($models)) {
            $next = null;

            foreach ($models as $model) {
                if (! in_array($model, $ordered, true) && array_diff($prerequisites[$model], $ordered) === []) {
                    $next = $model;
                    break;
                }
            }

            if ($next === null) {
                $remaining = array_values(array_diff($models, $ordered));

                throw new InvalidRetentionPolicy(implode(', ', $remaining), [
                    'blockedBy() forms a cycle between ['.implode(', ', $remaining).'], so none of these records can ever be deleted.',
                ]);
            }

            $ordered[] = $next;
        }

        return $ordered;
    }

    /**
     * @param  class-string<Model>  $model
     * @return list<class-string<Model>>
     */
    private function blockingModels(string $model): array
    {
        $instance = new $model;

        return array_map(
            fn (string $relation): string => RelationResolver::resolve($instance, $relation)->getRelated()::class,
            $this->policies->for($model)->blockingRelations,
        );
    }
}
