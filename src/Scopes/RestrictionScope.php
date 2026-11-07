<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use ItsJustVita\Retention\Database\RetentionColumns;

/**
 * Hides records whose processing is restricted. Access them explicitly with
 * withRestricted() or onlyRestricted().
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class RestrictionScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn(RetentionColumns::RESTRICTED_AT));
    }

    /**
     * @param  Builder<*>  $builder
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('withRestricted', function (Builder $builder, bool $withRestricted = true): Builder {
            $builder->withoutGlobalScope(self::class);

            return $withRestricted
                ? $builder
                : $builder->whereNull($builder->getModel()->qualifyColumn(RetentionColumns::RESTRICTED_AT));
        });

        $builder->macro('withoutRestricted', fn (Builder $builder): Builder => $builder
            ->withoutGlobalScope(self::class)
            ->whereNull($builder->getModel()->qualifyColumn(RetentionColumns::RESTRICTED_AT)));

        $builder->macro('onlyRestricted', fn (Builder $builder): Builder => $builder
            ->withoutGlobalScope(self::class)
            ->whereNotNull($builder->getModel()->qualifyColumn(RetentionColumns::RESTRICTED_AT)));
    }
}
