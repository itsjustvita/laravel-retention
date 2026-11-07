<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\RetentionPolicy;

class Customer extends Model
{
    use HasRetention;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['contract_ended_at' => 'datetime'];
    }

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('customer_master_data', 'Customer master data')
            ->rule('bgb_limitation_period')
            ->startsAt('contract_ended_at', StartEvent::EndOfRelationship)
            ->action('anonymize')
            ->anonymizeUsing(fn (Customer $customer) => $customer->forceFill([
                'name' => 'deleted', 'email' => null, 'phone' => null,
            ])->saveQuietly())
            ->responsible('Sales');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
