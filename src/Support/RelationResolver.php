<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Resolves relation names without running arbitrary model methods on real
 * records, so a name like "delete" can never delete the record it is checked
 * on.
 *
 * Eloquent has no way to ask whether a method is a relation, and relation
 * methods often have no return type. A name is therefore rejected if it
 * exists on the base model or cannot be a relation by its signature; a method
 * without a conclusive return type is tried on a blank, unsaved instance, and
 * only a method that returns a relation there is ever called on the record.
 */
final class RelationResolver
{
    /**
     * @return Relation<Model, Model, mixed>
     *
     * @throws InvalidArgumentException if the name is not a relation of the model
     */
    public static function resolve(Model $model, string $name): Relation
    {
        $relation = self::violation($model, $name) === null ? $model->{$name}() : null;

        if (! $relation instanceof Relation) {
            throw new InvalidArgumentException('['.$model::class."] has no relation [{$name}].");
        }

        return $relation;
    }

    /**
     * Why the name is not a relation of the model; null if it is one.
     */
    public static function violation(Model $model, string $name): ?string
    {
        if (! method_exists($model, $name)) {
            return "relation [{$name}] does not exist on the model.";
        }

        if (! self::canBeRelation($model, $name)) {
            return "[{$name}] is not an Eloquent relation.";
        }

        try {
            $relation = $model->newInstance()->{$name}();
        } catch (Throwable $e) {
            return "relation [{$name}] could not be resolved: {$e->getMessage()}";
        }

        return $relation instanceof Relation ? null : "[{$name}] is not an Eloquent relation.";
    }

    private static function canBeRelation(Model $model, string $name): bool
    {
        if (method_exists(Model::class, $name)) {
            return false;
        }

        $method = new ReflectionMethod($model, $name);

        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        $type = $method->getReturnType();

        // No or a union return type: only the blank instance can tell.
        return ! $type instanceof ReflectionNamedType
            || (! $type->isBuiltin() && is_a($type->getName(), Relation::class, true));
    }
}
