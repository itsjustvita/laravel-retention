<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\RetentionPolicy;

class Order extends Model
{
    use HasRetention;

    protected $guarded = [];

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('orders', 'Orders')
            ->rule('bgb_limitation_period')
            ->startsAt('completed_at', StartEvent::EndOfTransaction)
            ->action('delete')
            ->blockedBy(['invoices'])
            ->responsible('Sales');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
