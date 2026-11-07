<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Events;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\RetentionPolicy;

/**
 * A record was anonymised.
 */
final readonly class RecordAnonymized
{
    public function __construct(
        public Model $model,
        public RetentionPolicy $policy,
        public Trigger $trigger,
    ) {}
}
