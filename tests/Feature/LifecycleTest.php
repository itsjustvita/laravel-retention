<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ItsJustVita\Retention\Enums\ErasureStatus;
use ItsJustVita\Retention\Erasure;
use ItsJustVita\Retention\Models\RetentionLog;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;

/**
 * @return list<array{action: string, trigger: string}>
 */
function logEntries(): array
{
    return RetentionLog::query()->orderBy('id')->get(['action', 'trigger'])->toArray();
}

it('takes a customer from contract to deletion', function () {
    config([
        'retention.timezone' => 'Europe/Berlin',
        'retention.log.key' => 'test-log-key',
        'retention.log.retention' => 'P20Y', // keep every entry for the assertions below
        'retention.models.paths' => [],
        'retention.models.additional' => [Customer::class, Invoice::class],
    ]);

    // Customer with two invoices.
    $this->travelTo(at('2025-03-15 10:00'));
    $customer = createCustomer();
    $first = createInvoice($customer, ['issued_at' => '2025-03-15']);
    $this->travelTo(at('2026-02-01 10:00'));
    $second = createInvoice($customer, ['issued_at' => '2026-02-01']);

    // While the contract runs, nothing is due, and an erasure request changes nothing.
    $this->artisan('retention:run')->assertSuccessful();
    $early = Erasure::for($customer)->execute();

    expect($early->withStatus(ErasureStatus::StartEventPending))->toHaveCount(1)
        ->and(Customer::find($customer->id))->not->toBeNull()
        ->and(RetentionLog::count())->toBe(0);

    // The contract ends; the 3-year period starts at the end of 2026.
    $this->travelTo(at('2026-06-30 12:00'));
    $customer->forceFill(['contract_ended_at' => now()])->save();

    // Erasure request: everything is kept for now, but restricted.
    $this->travelTo(at('2026-09-01 09:00'));
    $report = Erasure::for($customer)->including(['invoices'])->execute();

    expect($report->withStatus(ErasureStatus::Restricted))->toHaveCount(3)
        ->and($report->toMarkdown('de'))->toContain('bis zum 01.01.2030')
        ->and($report->toMarkdown('de'))->toContain('bis zum 01.01.2034')
        ->and($report->toMarkdown('de'))->toContain('bis zum 01.01.2035')
        ->and(Customer::find($customer->id))->toBeNull()
        ->and(Invoice::query()->count())->toBe(0)
        ->and(Customer::withRestricted()->count())->toBe(1)
        ->and(Invoice::withRestricted()->count())->toBe(2);

    // Up to the last second of the limitation period, nothing happens.
    $this->travelTo(at('2029-12-31 23:59:59'));
    $this->artisan('retention:run')->assertSuccessful();

    expect(DB::table('customers')->value('name'))->toBe('Erika Mustermann');

    // The limitation period expires: the customer is anonymised, the invoices stay restricted.
    $this->travelTo(at('2030-01-01 00:00:00'));
    $this->artisan('retention:run')->assertSuccessful();

    expect(DB::table('customers')->value('name'))->toBe('deleted')
        ->and(DB::table('customers')->value('email'))->toBeNull()
        ->and(DB::table('customers')->value('restricted_at'))->toBeNull()
        ->and(DB::table('customers')->value('anonymized_at'))->not->toBeNull()
        ->and(Invoice::withRestricted()->count())->toBe(2);

    // A tax audit holds the first invoice beyond its period.
    $this->travelTo(at('2033-06-01'));
    $first->placeHold('Tax audit 2033', until: at('2034-06-01'));

    $this->travelTo(at('2034-01-01'));
    $this->artisan('retention:run')->assertSuccessful();

    expect(Invoice::withRestricted()->count())->toBe(2);

    // The hold has expired: the first invoice goes, the second one still has a year left.
    $this->travelTo(at('2034-06-01'));
    $this->artisan('retention:run')->assertSuccessful();

    expect(Invoice::withRestricted()->pluck('id')->all())->toBe([$second->id]);

    // The second invoice's period expires. A further run changes nothing.
    $this->travelTo(at('2035-01-01'));
    $this->artisan('retention:run')->assertSuccessful();
    $this->artisan('retention:run')->assertSuccessful();

    expect(DB::table('invoices')->count())->toBe(0)
        ->and(DB::table('customers')->count())->toBe(1);

    // The log proves every step, in order, without storing any record ID.
    expect(logEntries())->toBe([
        ['action' => 'restricted', 'trigger' => 'erasure_request'],
        ['action' => 'restricted', 'trigger' => 'erasure_request'],
        ['action' => 'restricted', 'trigger' => 'erasure_request'],
        ['action' => 'anonymized', 'trigger' => 'schedule'],
        ['action' => 'deleted', 'trigger' => 'schedule'],
        ['action' => 'deleted', 'trigger' => 'schedule'],
    ])
        ->and(RetentionLog::verify(Invoice::class, $first->id)->pluck('action')->all())->toBe(['restricted', 'deleted'])
        ->and(RetentionLog::verify(Customer::class, $customer->id)->pluck('action')->all())->toBe(['restricted', 'anonymized']);
});
