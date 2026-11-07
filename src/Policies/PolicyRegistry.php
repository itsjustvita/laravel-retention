<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Policies;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Exceptions\MissingRetentionPolicy;
use ItsJustVita\Retention\RetentionPolicy;

/**
 * Resolves and caches the retention policies of models.
 */
final class PolicyRegistry
{
    /** @var array<string, RetentionPolicy> */
    private array $policies = [];

    /**
     * Whether the class is an Eloquent model that uses HasRetention.
     *
     * @phpstan-assert-if-true =class-string<Model> $class
     */
    public function has(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Model::class)
            && in_array(HasRetention::class, class_uses_recursive($class), true);
    }

    public function for(string $model): RetentionPolicy
    {
        if (isset($this->policies[$model])) {
            return $this->policies[$model];
        }

        if (! $this->has($model) || ! method_exists($model, 'retentionPolicy')) {
            throw MissingRetentionPolicy::for($model);
        }

        $policy = $model::retentionPolicy();

        if (! $policy instanceof RetentionPolicy) {
            throw MissingRetentionPolicy::for($model);
        }

        return $this->policies[$model] = $policy;
    }

    public function flush(): void
    {
        $this->policies = [];
    }
}
