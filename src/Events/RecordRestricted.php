<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Events;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\RetentionPolicy;

/**
 * Processing of a record was restricted (Art. 18 GDPR, § 35 BDSG).
 */
final readonly class RecordRestricted
{
    public function __construct(
        public Model $model,
        public RetentionPolicy $policy,
        public Trigger $trigger,
    ) {}
}
