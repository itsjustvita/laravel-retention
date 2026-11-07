<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Events\RecordRestricted;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;

it('hides restricted records from normal queries', function () {
    $visible = createCustomer(['name' => 'Visible']);
    $restricted = createCustomer(['name' => 'Restricted']);
    $restricted->restrict();

    expect(Customer::pluck('name')->all())->toBe(['Visible'])
        ->and(Customer::find($restricted->id))->toBeNull()
        ->and(Customer::count())->toBe(1);
});

it('gives explicit access to restricted records', function () {
    createCustomer(['name' => 'Visible']);
    createCustomer(['name' => 'Restricted'])->restrict();

    expect(Customer::withRestricted()->orderBy('name')->pluck('name')->all())->toBe(['Restricted', 'Visible'])
        ->and(Customer::onlyRestricted()->pluck('name')->all())->toBe(['Restricted'])
        ->and(Customer::withRestricted(false)->pluck('name')->all())->toBe(['Visible'])
        ->and(Customer::withRestricted()->withoutRestricted()->pluck('name')->all())->toBe(['Visible']);
});

it('hides restricted records in relations', function () {
    $customer = createCustomer();
    createInvoice($customer);
    createInvoice($customer)->restrict();

    expect($customer->invoices()->count())->toBe(1)
        ->and($customer->invoices()->withRestricted()->count())->toBe(2);
});

it('keeps restricted records hidden when trashed records are included', function () {
    $customer = createCustomer();
    $invoice = createInvoice($customer);
    $invoice->restrict();
    $invoice->delete();

    expect(Invoice::withTrashed()->count())->toBe(0)
        ->and(Invoice::withTrashed()->withRestricted()->count())->toBe(1);
});

it('restricts a record without touching updated_at and dispatches an event', function () {
    Event::fake([RecordRestricted::class]);
    $this->travelTo(at('2025-01-01 10:00'));
    $customer = createCustomer();

    $this->travelTo(at('2026-05-04 12:00'));

    expect($customer->restrict())->toBeTrue()
        ->and($customer->isRestricted())->toBeTrue()
        ->and($customer->isDirty())->toBeFalse();

    $fresh = Customer::withRestricted()->findOrFail($customer->id);

    expect($fresh->restricted_at?->equalTo(at('2026-05-04 12:00')))->toBeTrue()
        ->and($fresh->updated_at?->equalTo(at('2025-01-01 10:00')))->toBeTrue();

    Event::assertDispatched(RecordRestricted::class, fn (RecordRestricted $event) => $event->model->is($customer)
        && $event->trigger === Trigger::Manual
        && $event->policy->category === 'customer_master_data');
});

it('passes the trigger to the event', function () {
    Event::fake([RecordRestricted::class]);

    createCustomer()->restrict(Trigger::ErasureRequest);

    Event::assertDispatched(RecordRestricted::class, fn (RecordRestricted $event) => $event->trigger === Trigger::ErasureRequest);
});

it('does nothing when the record is already restricted', function () {
    $customer = createCustomer();
    $this->travelTo(at('2026-01-01 12:00'));
    $customer->restrict();

    Event::fake([RecordRestricted::class]);
    $this->travelTo(at('2026-06-01'));

    expect($customer->restrict())->toBeFalse()
        ->and(Customer::withRestricted()->findOrFail($customer->id)->restricted_at?->equalTo(at('2026-01-01 12:00')))->toBeTrue();

    Event::assertNotDispatched(RecordRestricted::class);
});

it('lifts a restriction', function () {
    $customer = createCustomer();
    $customer->restrict();

    expect($customer->unrestrict())->toBeTrue()
        ->and($customer->isRestricted())->toBeFalse()
        ->and(Customer::find($customer->id))->not->toBeNull()
        ->and($customer->unrestrict())->toBeFalse();
});
