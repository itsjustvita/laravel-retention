<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use ItsJustVita\Retention\Actions\ActionExecutor;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Log\LogHasher;
use ItsJustVita\Retention\Models\RetentionLog;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

beforeEach(function () {
    config([
        'retention.timezone' => 'Europe/Berlin',
        'retention.log.key' => 'test-log-key',
        'retention.models.paths' => [],
        'retention.models.additional' => [Customer::class, Invoice::class, ServerLog::class],
    ]);
});

it('logs a deletion with a pseudonymised record ID', function () {
    $invoice = createInvoice(createCustomer(), ['issued_at' => '2015-01-01']);
    $this->travelTo(at('2026-01-01 03:00'));

    $this->artisan('retention:run')->assertSuccessful();

    $entry = RetentionLog::query()->sole();

    expect($entry->model)->toBe(Invoice::class)
        ->and($entry->record_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($entry->record_hash)->not->toContain((string) $invoice->id)
        ->and($entry->key_id)->toBe(app(LogHasher::class)->currentKeyId())
        ->and($entry->category)->toBe('invoices')
        ->and($entry->rule)->toBe('ao_accounting_voucher')
        ->and($entry->action)->toBe('deleted')
        ->and($entry->trigger)->toBe('schedule')
        ->and($entry->created_at?->equalTo(at('2026-01-01 03:00')))->toBeTrue();
});

it('logs anonymisations and restrictions with their trigger', function () {
    $customer = createCustomer();
    $customer->restrict();
    app(ActionExecutor::class)->apply($customer, Trigger::ErasureRequest);

    expect(RetentionLog::query()->orderBy('id')->get(['action', 'trigger'])->toArray())->toBe([
        ['action' => 'restricted', 'trigger' => 'manual'],
        ['action' => 'anonymized', 'trigger' => 'erasure_request'],
    ]);
});

it('finds the entries of a known record without storing its ID', function () {
    $first = createInvoice(createCustomer(), ['issued_at' => '2015-01-01']);
    $second = createInvoice(createCustomer(), ['issued_at' => '2015-01-01']);
    $this->travelTo(at('2026-01-01'));
    $this->artisan('retention:run')->assertSuccessful();

    expect(RetentionLog::verify(Invoice::class, $first->id))->toHaveCount(1)
        ->and(RetentionLog::verify(Invoice::class, $second->id))->toHaveCount(1)
        ->and(RetentionLog::verify(Invoice::class, $first->id)->first()?->is(RetentionLog::verify(Invoice::class, $second->id)->first()))->toBeFalse()
        ->and(RetentionLog::verify(Invoice::class, 999_999))->toHaveCount(0)
        ->and(RetentionLog::verify(Customer::class, $first->id))->toHaveCount(0);
});

it('verifies entries written with a rotated key', function () {
    $invoice = createInvoice(createCustomer(), ['issued_at' => '2015-01-01']);
    $this->travelTo(at('2026-01-01'));
    $this->artisan('retention:run')->assertSuccessful();

    config(['retention.log.key' => 'new-log-key']);
    app()->forgetInstance(LogHasher::class);

    expect(RetentionLog::verify(Invoice::class, $invoice->id))->toHaveCount(0);

    config(['retention.log.previous_keys' => ['test-log-key']]);
    app()->forgetInstance(LogHasher::class);

    expect(RetentionLog::verify(Invoice::class, $invoice->id))->toHaveCount(1);
});

it('falls back to the application key', function () {
    config(['retention.log.key' => null, 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

    $hasher = app(LogHasher::class);

    expect($hasher->usesApplicationKey())->toBeTrue()
        ->and($hasher->hash(Invoice::class, 1)['hash'])->toMatch('/^[0-9a-f]{64}$/');
});

it('refuses to log without any key', function () {
    config(['retention.log.key' => null, 'app.key' => null]);

    app(LogHasher::class);
})->throws(RuntimeException::class, 'Set RETENTION_LOG_KEY (or APP_KEY)');

it('writes nothing when the log is disabled', function () {
    config(['retention.log.enabled' => false]);
    createInvoice(createCustomer(), ['issued_at' => '2015-01-01']);
    $this->travelTo(at('2026-01-01'));

    $this->artisan('retention:run')->assertSuccessful();

    expect(RetentionLog::count())->toBe(0);
});

it('purges log entries after the log retention period', function () {
    createInvoice(createCustomer(), ['issued_at' => '2015-01-01']);
    $this->travelTo(at('2026-01-01'));
    $this->artisan('retention:run')->assertSuccessful();

    $this->travelTo(at('2028-12-31 23:59:59'));
    $this->artisan('retention:run')->assertSuccessful();
    expect(RetentionLog::count())->toBe(1);

    $this->travelTo(at('2029-01-01 00:00:01'));
    $this->artisan('retention:run')
        ->expectsOutputToContain('Purged 1 log entry/entries')
        ->assertSuccessful();
    expect(RetentionLog::count())->toBe(0);
});

it('refuses to run without a log key, before changing anything', function () {
    config(['retention.log.key' => null, 'app.key' => null]);
    createInvoice(createCustomer(), ['issued_at' => '2015-01-01']);
    $this->travelTo(at('2026-01-01'));

    $this->artisan('retention:run')
        ->expectsOutputToContain('the deletion log needs a key to pseudonymise record IDs')
        ->assertFailed();

    expect(Invoice::count())->toBe(1);
});

it('reports missing package tables', function () {
    Schema::drop('retention_log');

    $this->artisan('retention:run')
        ->expectsOutputToContain('table [retention_log] does not exist; publish and run the package migrations')
        ->assertFailed();
});
