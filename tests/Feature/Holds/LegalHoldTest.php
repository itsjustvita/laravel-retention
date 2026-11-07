<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ItsJustVita\Retention\Actions\ActionExecutor;
use ItsJustVita\Retention\Enums\ActionOutcome;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Execution\DueRecords;
use ItsJustVita\Retention\Models\RetentionHold;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;

beforeEach(function () {
    config(['retention.timezone' => 'Europe/Berlin']);
});

it('places and releases a legal hold', function () {
    $this->travelTo(at('2026-03-01 10:00'));
    $invoice = createInvoice(createCustomer());

    $hold = $invoice->placeHold('Tax audit 2026', until: at('2027-01-01'), by: 'auditor-7');

    expect($invoice->isOnHold())->toBeTrue()
        ->and($hold->reason)->toBe('Tax audit 2026')
        ->and($hold->created_by)->toBe('auditor-7')
        ->and($hold->until?->equalTo(at('2027-01-01')))->toBeTrue()
        ->and($hold->holdable->is($invoice))->toBeTrue();

    $this->travelTo(at('2026-04-01 10:00'));

    expect($invoice->releaseHold())->toBe(1)
        ->and($invoice->isOnHold())->toBeFalse()
        ->and($hold->fresh()?->released_at?->equalTo(at('2026-04-01 10:00')))->toBeTrue();
});

it('lets a hold expire by itself at its end date', function () {
    $this->travelTo(at('2026-03-01'));
    $invoice = createInvoice(createCustomer());
    $invoice->placeHold('Litigation', until: at('2026-06-01'));

    $this->travelTo(at('2026-05-31 23:59:59'));
    expect($invoice->isOnHold())->toBeTrue();

    $this->travelTo(at('2026-06-01 00:00:00'));
    expect($invoice->isOnHold())->toBeFalse();
});

it('inherits holds through relations, even from restricted records', function () {
    $customer = createCustomer();
    $order = createOrder($customer);
    $viaCustomer = createInvoice($customer);
    $viaOrder = createInvoice(createCustomer(), ['order_id' => $order->id]);
    $unrelated = createInvoice(createCustomer());

    $customer->restrict();
    $customer->placeHold('Pending litigation');
    $order->placeHold('Disputed delivery');

    expect($viaCustomer->isOnHold())->toBeTrue()
        ->and($viaOrder->isOnHold())->toBeTrue()
        ->and($unrelated->isOnHold())->toBeFalse();
});

it('follows nested relations in inheritsHoldsFrom()', function () {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('probe', 'Probe')
        ->rule('server_logs')->startsAt('created_at', 'collection')->action('delete')->responsible('IT')
        ->inheritsHoldsFrom('invoices.order');
    $customer = createCustomer();
    $order = createOrder(createCustomer());
    createInvoice($customer, ['order_id' => $order->id]);
    $probe = PolicyProbe::query()->findOrFail($customer->id);

    expect($probe->isOnHold())->toBeFalse();

    $order->placeHold('Disputed delivery');

    expect($probe->isOnHold())->toBeTrue();
});

it('never deletes or anonymises a record on hold', function () {
    $customer = createCustomer();
    $invoice = createInvoice($customer);
    $customer->placeHold('Pending litigation');

    expect(app(ActionExecutor::class)->apply($customer, Trigger::Schedule))->toBe(ActionOutcome::OnHold)
        ->and(app(ActionExecutor::class)->apply($invoice, Trigger::Schedule))->toBe(ActionOutcome::OnHold)
        ->and(DB::table('invoices')->count())->toBe(1)
        ->and(DB::table('customers')->value('name'))->toBe('Erika Mustermann');
});

it('excludes held records from the due selection', function () {
    $customer = createCustomer();
    $held = createInvoice($customer, ['issued_at' => '2015-01-01']);
    $free = createInvoice(createCustomer(), ['issued_at' => '2015-01-01']);
    $customer->placeHold('Pending litigation');
    $records = app(DueRecords::class);

    expect($records->query(Invoice::class, at('2026-01-01'))->pluck('id')->all())->toBe([$free->id])
        ->and($records->query(Invoice::class, at('2026-01-01'), excludeHeld: false)->orderBy('id')->pluck('id')->all())
        ->toBe([$held->id, $free->id]);
});

it('shows held records in retention:run and retention:due and keeps them', function () {
    config(['retention.models.paths' => [], 'retention.models.additional' => [Invoice::class]]);
    $customer = createCustomer();
    createInvoice($customer, ['issued_at' => '2015-01-01'])->placeHold('Tax audit');
    createInvoice($customer, ['issued_at' => '2015-01-01']);
    $this->travelTo(at('2026-01-01'));

    $this->artisan('retention:due')
        ->expectsTable(['Category', 'Model', 'Due now', 'Blocked', 'On hold', 'Due within 30 days', 'Restricted'], [
            ['invoices', Invoice::class, '1', '0', '1', '0', '0'],
        ])
        ->assertSuccessful();

    $this->artisan('retention:run')
        ->expectsTable(['Category', 'Model', 'Deleted', 'Anonymised', 'Blocked', 'On hold', 'Skipped'], [
            ['invoices', Invoice::class, '1', '0', '0', '1', '0'],
        ])
        ->assertSuccessful();

    expect(DB::table('invoices')->count())->toBe(1);
});

it('purges released and expired holds after their retention period', function () {
    config(['retention.models.paths' => [], 'retention.models.additional' => [Invoice::class]]);
    $invoice = createInvoice(createCustomer(), ['issued_at' => '2025-01-01']);

    $this->travelTo(at('2022-01-01'));
    $released = $invoice->placeHold('Old audit');
    $invoice->releaseHold();
    $expired = $invoice->placeHold('Old litigation', until: at('2022-06-01'));

    $this->travelTo(at('2025-05-01'));
    $recentlyReleased = $invoice->placeHold('Recent audit');
    $invoice->releaseHold();
    $active = $invoice->placeHold('Current audit');

    $this->travelTo(at('2025-07-01'));
    $this->artisan('retention:run')
        ->expectsOutputToContain('Purged 2 released or expired legal hold(s)')
        ->assertSuccessful();

    expect(RetentionHold::query()->orderBy('id')->pluck('id')->all())->toBe([$recentlyReleased->id, $active->id])
        ->and(RetentionHold::find($released->id))->toBeNull()
        ->and(RetentionHold::find($expired->id))->toBeNull();
});
