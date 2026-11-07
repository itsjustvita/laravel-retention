<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use ItsJustVita\Retention\Attributes\Retention;
use ItsJustVita\Retention\Holds\LegalHolds;
use ItsJustVita\Retention\Models\RetentionHold;
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

    /**
     * @return MorphMany<RetentionHold, $this>
     */
    public function retentionHolds(): MorphMany
    {
        return $this->morphMany(RetentionHold::class, 'holdable');
    }

    /**
     * Places a legal hold: until it is released or `until` has passed, the
     * record (and every record inheriting holds from it) is never deleted or
     * anonymised.
     *
     * @param  string|null  $by  who placed the hold; defaults to the authenticated user's ID
     */
    public function placeHold(string $reason, ?DateTimeInterface $until = null, ?string $by = null): RetentionHold
    {
        return app(LegalHolds::class)->place($this, $reason, $until, $by);
    }

    /**
     * Releases all active legal holds of this record.
     *
     * @return int number of released holds
     */
    public function releaseHold(): int
    {
        return app(LegalHolds::class)->release($this);
    }

    /**
     * Whether this record has an active hold, itself or through its
     * inheritsHoldsFrom() relations.
     */
    public function isOnHold(): bool
    {
        return app(LegalHolds::class)->isHeld($this);
    }
}
