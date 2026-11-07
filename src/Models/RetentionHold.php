<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A legal hold: while it is active, the held record is never deleted or
 * anonymised. Active while not released and `until` is unset or in the
 * future.
 *
 * Holds name the person who placed them, so released and expired holds are
 * purged after retention.holds.retention.
 *
 * @property int $id
 * @property string $holdable_type
 * @property int|string $holdable_id
 * @property string $reason
 * @property Carbon|null $until
 * @property string|null $created_by
 * @property Carbon|null $released_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $holdable
 */
class RetentionHold extends Model
{
    protected $table = 'retention_holds';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'until' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function holdable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query, ?CarbonInterface $now = null): void
    {
        self::whereActive($query, $now ?? CarbonImmutable::now());
    }

    /**
     * Restricts a query on retention_holds to holds active at `$now`.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function whereActive(Builder $query, CarbonInterface $now): void
    {
        $now = (new self)->fromDateTime(self::inApplicationTimezone($now));

        $query->whereNull($query->qualifyColumn('released_at'))
            ->where(fn (Builder $query) => $query
                ->whereNull($query->qualifyColumn('until'))
                ->orWhere($query->qualifyColumn('until'), '>', $now));
    }

    public function release(): bool
    {
        if ($this->released_at !== null) {
            return false;
        }

        return $this->forceFill(['released_at' => $this->freshTimestamp()])->save();
    }

    /**
     * Converts an instant to the application timezone, in which Laravel
     * writes datetime columns.
     */
    public static function inApplicationTimezone(CarbonInterface $instant): CarbonImmutable
    {
        $timezone = config('app.timezone');

        return CarbonImmutable::instance($instant)->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC');
    }
}
