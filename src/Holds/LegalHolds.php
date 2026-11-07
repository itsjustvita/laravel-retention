<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Holds;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ItsJustVita\Retention\Models\RetentionHold;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Rules\Period;
use Throwable;

/**
 * Legal holds: placing, releasing, checking and purging them.
 *
 * A record is held if it has an active hold itself, or if a record reached
 * through one of its inheritsHoldsFrom() relations has one. Inherited holds
 * are looked up across all global scopes, so a restricted or soft-deleted
 * customer still protects its invoices. Inheritance is not transitive;
 * list nested paths explicitly (e.g. 'order.customer').
 */
final readonly class LegalHolds
{
    public function __construct(
        private PolicyRegistry $policies,
        private Repository $config,
        private AuthFactory $auth,
    ) {}

    public function place(Model $record, string $reason, ?DateTimeInterface $until = null, ?string $by = null): RetentionHold
    {
        return RetentionHold::query()->create([
            'holdable_type' => $record->getMorphClass(),
            'holdable_id' => $record->getKey(),
            'reason' => $reason,
            'until' => $until !== null ? RetentionHold::inApplicationTimezone(CarbonImmutable::instance($until)) : null,
            'created_by' => $by ?? $this->currentUser(),
        ]);
    }

    /**
     * Releases all active holds of the record.
     *
     * @return int number of released holds
     */
    public function release(Model $record): int
    {
        $released = 0;

        foreach (RetentionHold::query()->whereMorphedTo('holdable', $record)->active()->get() as $hold) {
            $released += (int) $hold->release();
        }

        return $released;
    }

    public function isHeld(Model $record, ?CarbonInterface $now = null): bool
    {
        $query = $record->newQueryWithoutScopes()->whereKey($record->getKey());

        return $this->whereHeld($query, $this->policies->for($record::class), $now ?? CarbonImmutable::now())->exists();
    }

    /**
     * Restricts a query to records that are held ($held = true) or not held.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function whereHeld(Builder $query, RetentionPolicy $policy, CarbonInterface $now, bool $held = true): Builder
    {
        $condition = function (Builder $query) use ($policy, $now): void {
            $query->whereHas('retentionHolds', fn (Builder $holds) => RetentionHold::whereActive($holds, $now));

            foreach ($policy->holdRelations as $path) {
                $query->orWhere(fn (Builder $query) => $this->whereRelationHeld($query, explode('.', $path), $now));
            }
        };

        return $held ? $query->where($condition) : $query->whereNot($condition);
    }

    /**
     * Deletes holds that were released or expired longer ago than the
     * configured retention period.
     *
     * @return int number of purged holds
     */
    public function purge(CarbonInterface $now): int
    {
        $cutoff = (new RetentionHold)->fromDateTime(RetentionHold::inApplicationTimezone($this->retention()->subtractFrom(CarbonImmutable::instance($now))));

        return RetentionHold::query()
            ->where(fn (Builder $query) => $query
                ->where('released_at', '<', $cutoff)
                ->orWhere(fn (Builder $query) => $query->whereNull('released_at')->where('until', '<', $cutoff)))
            ->delete();
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $segments
     */
    private function whereRelationHeld(Builder $query, array $segments, CarbonInterface $now): void
    {
        $relation = array_shift($segments);

        $query->whereHas((string) $relation, function (Builder $related) use ($segments, $now): void {
            $related->withoutGlobalScopes();

            if ($segments === []) {
                $related->whereHas('retentionHolds', fn (Builder $holds) => RetentionHold::whereActive($holds, $now));
            } else {
                $this->whereRelationHeld($related, $segments, $now);
            }
        });
    }

    private function retention(): Period
    {
        $value = $this->config->get('retention.holds.retention', 'P3Y');

        if (! is_string($value)) {
            throw new InvalidArgumentException('[retention.holds.retention] must be an ISO 8601 duration such as P3Y.');
        }

        return Period::parse($value);
    }

    private function currentUser(): ?string
    {
        try {
            $id = $this->auth->guard()->id();
        } catch (Throwable) {
            return null;
        }

        return is_int($id) || is_string($id) ? (string) $id : null;
    }
}
