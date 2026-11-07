<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Concerns;

use Illuminate\Database\Eloquent\Builder;
use ItsJustVita\Retention\Database\RetentionColumns;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Events\RecordRestricted;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Scopes\RestrictionScope;

/**
 * Restriction of processing (Art. 18 GDPR, § 35 BDSG) for models whose table
 * has the restricted_at column ($table->restrictable()).
 *
 * Restricted records are hidden from normal queries. Required for models
 * whose retention rule blocks erasure, because an erasure request restricts
 * them until their period expires.
 *
 * @method static Builder<static> withRestricted(bool $withRestricted = true)
 * @method static Builder<static> withoutRestricted()
 * @method static Builder<static> onlyRestricted()
 */
trait Restrictable
{
    public static function bootRestrictable(): void
    {
        static::addGlobalScope(new RestrictionScope);
    }

    public function initializeRestrictable(): void
    {
        $this->mergeCasts([RetentionColumns::RESTRICTED_AT => 'datetime']);
    }

    /**
     * Restricts processing of this record. Does not touch updated_at.
     *
     * @return bool false if the record was already restricted
     */
    public function restrict(Trigger $trigger = Trigger::Manual): bool
    {
        if ($this->isRestricted()) {
            return false;
        }

        $this->writeRestriction($this->freshTimestamp());

        event(new RecordRestricted($this, app(PolicyRegistry::class)->for(static::class), $trigger));

        return true;
    }

    /**
     * Lifts the restriction, e.g. once contested data has been verified.
     *
     * @return bool false if the record was not restricted
     */
    public function unrestrict(): bool
    {
        if (! $this->isRestricted()) {
            return false;
        }

        $this->writeRestriction(null);

        return true;
    }

    public function isRestricted(): bool
    {
        return $this->getAttribute(RetentionColumns::RESTRICTED_AT) !== null;
    }

    private function writeRestriction(mixed $value): void
    {
        // Base query builder, so updated_at stays untouched.
        $this->setKeysForSaveQuery($this->newModelQuery())->toBase()->update([RetentionColumns::RESTRICTED_AT => $value]);

        $this->setAttribute(RetentionColumns::RESTRICTED_AT, $value);
        $this->syncOriginalAttribute(RetentionColumns::RESTRICTED_AT);
    }
}
