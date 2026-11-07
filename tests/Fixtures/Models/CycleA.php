<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\RetentionPolicy;

/**
 * Blocked by CycleB, which is blocked by CycleA: neither could ever be deleted.
 */
class CycleA extends Model
{
    use HasRetention;

    protected $table = 'server_logs';

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('cycle_a', 'Cycle A')
            ->rule('server_logs')
            ->startsAt('logged_at', StartEvent::Collection)
            ->action('delete')
            ->blockedBy(['bs'])
            ->responsible('IT');
    }

    /**
     * @return HasMany<CycleB, $this>
     */
    public function bs(): HasMany
    {
        return $this->hasMany(CycleB::class, 'id');
    }
}
