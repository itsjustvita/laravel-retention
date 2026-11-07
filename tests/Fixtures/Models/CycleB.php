<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\RetentionPolicy;

class CycleB extends Model
{
    use HasRetention;

    protected $table = 'server_logs';

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('cycle_b', 'Cycle B')
            ->rule('server_logs')
            ->startsAt('logged_at', StartEvent::Collection)
            ->action('delete')
            ->blockedBy(['as'])
            ->responsible('IT');
    }

    /**
     * @return HasMany<CycleA, $this>
     */
    public function as(): HasMany
    {
        return $this->hasMany(CycleA::class, 'id');
    }
}
