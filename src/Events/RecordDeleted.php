<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Events;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\RetentionPolicy;

/**
 * A record was deleted because its retention period expired or it was erased.
 */
final readonly class RecordDeleted
{
    public function __construct(
        public Model $model,
        public RetentionPolicy $policy,
        public Trigger $trigger,
    ) {}
}
