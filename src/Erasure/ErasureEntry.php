<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Erasure;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\ErasureReason;
use ItsJustVita\Retention\Enums\ErasureStatus;

/**
 * The outcome of an erasure request for one record.
 */
final readonly class ErasureEntry
{
    /**
     * @param  class-string<Model>  $model
     * @param  string|null  $category  null if the model has no retention policy
     * @param  CarbonImmutable|null  $scheduledFor  when a restricted record will receive its final action
     * @param  bool  $waitsForDependents  restricted until its blockedBy() relations are empty
     */
    public function __construct(
        public string $model,
        public int|string $key,
        public ErasureStatus $status,
        public ?string $category = null,
        public ?string $label = null,
        public ?Action $finalAction = null,
        public ?CarbonImmutable $scheduledFor = null,
        public bool $waitsForDependents = false,
        public ?string $erasureGround = null,
        public ?string $ruleKey = null,
        public ?ErasureReason $erasureReason = null,
    ) {}
}
