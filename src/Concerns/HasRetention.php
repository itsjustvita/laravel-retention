<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Concerns;

use ItsJustVita\Retention\Attributes\Retention;
use ItsJustVita\Retention\RetentionPolicy;

/**
 * Marks an Eloquent model as governed by a retention policy.
 *
 * Declare the policy by overriding retentionPolicy() or with the #[Retention]
 * attribute on the model class.
 */
trait HasRetention
{
    public static function retentionPolicy(): RetentionPolicy
    {
        return Retention::policyFor(static::class);
    }
}
