<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ItsJustVita\Retention\Attributes\Retention;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\StartEvent;

#[Retention(
    category: 'invoices',
    label: 'Customer invoices',
    rule: 'ao_accounting_voucher',
    startsAt: 'issued_at',
    startEvent: StartEvent::EndOfTransaction,
    action: Action::Delete,
    responsible: 'Accounting',
    inheritsHoldsFrom: ['customer'],
)]
class Invoice extends Model
{
    use HasRetention;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'date'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
