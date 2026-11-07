<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ItsJustVita\Retention\Enums\ErasureStatus;
use ItsJustVita\Retention\Erasure;
use ItsJustVita\Retention\Erasure\ErasureEntry;
use ItsJustVita\Retention\Erasure\ErasureReport;
use ItsJustVita\Retention\Events\ErasureCompleted;
use ItsJustVita\Retention\Events\RecordDeleted;
use ItsJustVita\Retention\Exceptions\MissingRetentionPolicy;
use ItsJustVita\Retention\Models\RetentionLog;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Note;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\RestrictableProbe;

beforeEach(function () {
    config(['retention.timezone' => 'Europe/Berlin']);
    $this->travelTo(at('2026-03-01 10:00'));
});

/**
 * @return array<string, string>
 */
function statuses(ErasureReport $report): array
{
    $statuses = [];

    foreach ($report->entries as $entry) {
        $statuses[class_basename($entry->model).'#'.$entry->key] = $entry->status->value;
    }

    return $statuses;
}

function entryFor(ErasureReport $report, string $model, int|string $key): ErasureEntry
{
    foreach ($report->entries as $entry) {
        if ($entry->model === $model && $entry->key === $key) {
            return $entry;
        }
    }

    throw new LogicException("No entry for {$model}#{$key}.");
}

it('restricts records while a blocking rule runs and reports when they will be erased', function () {
    $customer = createCustomer(['contract_ended_at' => '2025-06-01 10:00:00']);
    $invoice = createInvoice($customer, ['issued_at' => '2025-03-15']);

    $report = Erasure::for($customer)->including(['invoices'])->execute();

    expect(statuses($report))->toBe([
        "Invoice#{$invoice->id}" => 'restricted',
        "Customer#{$customer->id}" => 'restricted',
    ])
        ->and(Customer::find($customer->id))->toBeNull()
        ->and(Customer::withRestricted()->find($customer->id)?->isRestricted())->toBeTrue()
        ->and(Invoice::withRestricted()->find($invoice->id)?->isRestricted())->toBeTrue();

    $invoiceEntry = entryFor($report, Invoice::class, $invoice->id);
    $customerEntry = entryFor($report, Customer::class, $customer->id);

    expect($invoiceEntry->scheduledFor?->toIso8601String())->toBe('2034-01-01T00:00:00+01:00')
        ->and($invoiceEntry->erasureGround)->toBe('Art. 17 (3) (b) GDPR; § 35 (3) BDSG')
        ->and($invoiceEntry->category)->toBe('invoices')
        ->and($customerEntry->scheduledFor?->toIso8601String())->toBe('2029-01-01T00:00:00+01:00')
        ->and($customerEntry->erasureGround)->toBe('Art. 17 (3) (e) GDPR');
});

it('applies the final action right away when no blocking rule runs any more', function () {
    $customer = createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $invoice = createInvoice($customer, ['issued_at' => '2015-03-15']);

    $report = Erasure::for($customer)->including(['invoices'])->execute();

    expect(statuses($report))->toBe([
        "Invoice#{$invoice->id}" => 'deleted',
        "Customer#{$customer->id}" => 'anonymized',
    ])
        ->and(DB::table('invoices')->count())->toBe(0)
        ->and(DB::table('customers')->value('name'))->toBe('deleted');
});

it('leaves records alone whose period has not started', function () {
    $customer = createCustomer(['contract_ended_at' => null]);

    $report = Erasure::for($customer)->execute();

    expect(statuses($report))->toBe(["Customer#{$customer->id}" => 'start_event_pending'])
        ->and(Customer::find($customer->id))->not->toBeNull();
});

it('applies the final action before the start event when the policy opts in', function () {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('drafts', 'Drafts')
        ->rule('bgb_limitation_period')->startsAt('contract_ended_at', 'end_of_relationship')
        ->action('anonymize')->anonymizeUsing('anonymizeForRetention')->responsible('Sales')
        ->onErasureBeforeStart('apply');
    $probe = RestrictableProbe::query()->findOrFail(createCustomer(['contract_ended_at' => null])->id);

    expect(statuses(Erasure::for($probe)->execute()))->toBe(["RestrictableProbe#{$probe->id}" => 'anonymized'])
        ->and(DB::table('customers')->value('name'))->toBe('deleted');
});

it('keeps records on legal hold, including inherited holds', function () {
    $customer = createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $invoice = createInvoice($customer, ['issued_at' => '2015-03-15']);
    $customer->placeHold('Pending litigation');

    $report = Erasure::for($customer)->including(['invoices'])->execute();

    expect(statuses($report))->toBe([
        "Invoice#{$invoice->id}" => 'on_hold',
        "Customer#{$customer->id}" => 'on_hold',
    ])
        ->and(DB::table('invoices')->count())->toBe(1)
        ->and(DB::table('customers')->value('name'))->toBe('Erika Mustermann');
});

it('deletes children before their parents', function () {
    $customer = createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $order = createOrder($customer, ['completed_at' => '2020-06-01 10:00:00']);
    $invoice = createInvoice($customer, ['order_id' => $order->id, 'issued_at' => '2015-03-15']);

    $report = Erasure::for($customer)->including(['orders.invoices', 'invoices'])->execute();

    expect(statuses($report))->toBe([
        "Invoice#{$invoice->id}" => 'deleted',
        "Order#{$order->id}" => 'deleted',
        "Customer#{$customer->id}" => 'anonymized',
    ]);
});

it('restricts a parent while its children must be kept', function () {
    $customer = createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $order = createOrder($customer, ['completed_at' => '2020-06-01 10:00:00']);
    $invoice = createInvoice($customer, ['order_id' => $order->id, 'issued_at' => '2025-03-15']);

    $report = Erasure::for($customer)->including(['orders.invoices'])->execute();
    $orderEntry = entryFor($report, Order::class, $order->id);

    expect(statuses($report))->toBe([
        "Invoice#{$invoice->id}" => 'restricted',
        "Order#{$order->id}" => 'restricted',
        "Customer#{$customer->id}" => 'anonymized',
    ])
        ->and($orderEntry->scheduledFor)->toBeNull()
        ->and($orderEntry->waitsForDependents)->toBeTrue()
        ->and(Order::withRestricted()->find($order->id)?->isRestricted())->toBeTrue();
});

it('predicts the outcome in a dry run without changing anything', function () {
    $customer = createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $order = createOrder($customer, ['completed_at' => '2020-06-01 10:00:00']);
    createInvoice($customer, ['order_id' => $order->id, 'issued_at' => '2015-03-15']);
    $kept = createOrder($customer, ['completed_at' => '2020-06-01 10:00:00']);
    createInvoice($customer, ['order_id' => $kept->id, 'issued_at' => '2025-03-15']);

    $preview = Erasure::for($customer)->including(['orders.invoices'])->dryRun();

    expect($preview->dryRun)->toBeTrue()
        ->and(DB::table('invoices')->count())->toBe(2)
        ->and(DB::table('orders')->whereNotNull('restricted_at')->count())->toBe(0)
        ->and(DB::table('customers')->value('name'))->toBe('Erika Mustermann')
        ->and(RetentionLog::count())->toBe(0);

    expect(statuses(Erasure::for($customer)->including(['orders.invoices'])->execute()))->toBe(statuses($preview));
});

it('refuses to erase when an included relation has no retention policy', function () {
    $customer = createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    Note::create(['customer_id' => $customer->id, 'body' => 'Called about the invoice.']);

    expect(fn () => Erasure::for($customer)->including(['notes'])->execute())
        ->toThrow(MissingRetentionPolicy::class, Note::class);

    expect(DB::table('customers')->value('name'))->toBe('Erika Mustermann');

    $preview = Erasure::for($customer)->including(['notes'])->dryRun();

    expect(array_values(statuses($preview)))->toBe(['no_policy', 'anonymized']);
});

it('rejects unknown relations', function () {
    Erasure::for(createCustomer())->including(['tickets'])->execute();
})->throws(InvalidArgumentException::class, 'has no relation [tickets]');

it('rolls everything back when an action fails', function () {
    $customer = createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    createInvoice($customer, ['issued_at' => '2015-03-15']);
    createInvoice($customer, ['issued_at' => '2015-03-16']);
    $deletions = 0;
    Event::listen(RecordDeleted::class, function () use (&$deletions) {
        if (++$deletions === 2) {
            throw new RuntimeException('Storage unavailable');
        }
    });

    expect(fn () => Erasure::for($customer)->including(['invoices'])->execute())
        ->toThrow(RuntimeException::class, 'Storage unavailable');

    expect(DB::table('invoices')->count())->toBe(2)
        ->and(DB::table('customers')->value('name'))->toBe('Erika Mustermann')
        ->and(RetentionLog::count())->toBe(0);
});

it('logs every action as an erasure request and dispatches ErasureCompleted', function () {
    Event::fake([ErasureCompleted::class]);
    $customer = createCustomer(['contract_ended_at' => '2025-06-01 10:00:00']);
    createInvoice($customer, ['issued_at' => '2015-03-15']);

    $report = Erasure::for($customer)->including(['invoices'])->execute();

    expect(RetentionLog::query()->orderBy('id')->get(['action', 'trigger'])->toArray())->toBe([
        ['action' => 'deleted', 'trigger' => 'erasure_request'],
        ['action' => 'restricted', 'trigger' => 'erasure_request'],
    ]);

    Event::assertDispatched(ErasureCompleted::class, fn (ErasureCompleted $event) => $event->report === $report);
});

it('exports the report as an array', function () {
    $customer = createCustomer(['contract_ended_at' => '2025-06-01 10:00:00']);
    $invoice = createInvoice($customer, ['issued_at' => '2025-03-15']);

    $array = Erasure::for($customer)->including(['invoices'])->execute()->toArray();

    expect($array['subject'])->toBe(['model' => Customer::class, 'key' => $customer->id])
        ->and($array['dry_run'])->toBeFalse()
        ->and($array['processed_at'])->toBe('2026-03-01T10:00:00+01:00')
        ->and($array['categories']['invoices'])->toBe([
            'label' => 'Customer invoices',
            'records' => [[
                'model' => Invoice::class,
                'key' => $invoice->id,
                'status' => 'restricted',
                'final_action' => 'delete',
                'scheduled_for' => '2034-01-01T00:00:00+01:00',
                'waits_for_dependents' => false,
                'erasure_ground' => 'Art. 17 (3) (b) GDPR; § 35 (3) BDSG',
            ]],
        ]);
});

it('renders a reply for the data subject in English and German', function () {
    $customer = createCustomer(['contract_ended_at' => '2025-06-01 10:00:00']);
    createInvoice($customer, ['issued_at' => '2025-03-15']);
    createInvoice($customer, ['issued_at' => '2025-04-01']);
    createInvoice($customer, ['issued_at' => '2015-03-15']);

    $report = Erasure::for($customer)->including(['invoices'])->execute();

    expect($report->toMarkdown('en'))->toBe(<<<'MD'
        # Result of the erasure request

        Processed on 2026-03-01.

        ## Customer invoices

        - Deleted: 1 record.
        - Restricted: 2 records. We must keep this data until 2034-01-01 (Art. 17 (3) (b) GDPR; § 35 (3) BDSG). Until then it is blocked for any other use; afterwards it will be deleted.

        ## Customer master data

        - Restricted: 1 record. We must keep this data until 2029-01-01 (Art. 17 (3) (e) GDPR). Until then it is blocked for any other use; afterwards it will be anonymised.

        MD)
        ->and($report->toMarkdown('de'))->toContain('# Ergebnis des Löschantrags')
        ->and($report->toMarkdown('de'))->toContain('Bearbeitet am 01.03.2026.')
        ->and($report->toMarkdown('de'))->toContain('- Gelöscht: 1 Datensatz.')
        ->and($report->toMarkdown('de'))->toContain('- Eingeschränkt: 2 Datensätze. Wir müssen diese Daten bis zum 01.01.2034 aufbewahren (Art. 17 (3) (b) GDPR; § 35 (3) BDSG). Bis dahin sind sie für jede andere Verarbeitung gesperrt; danach werden sie gelöscht.');
});

it('marks a dry run in the reply', function () {
    $customer = createCustomer(['contract_ended_at' => null]);

    expect(Erasure::for($customer)->dryRun()->toMarkdown('en'))
        ->toContain('Preview only: nothing has been changed yet.')
        ->toContain('- Not erased: 1 record. The data is still needed, for example for an active contract.');
});

it('lists every status as an enum case', function () {
    expect(array_map(fn (ErasureStatus $status) => $status->value, ErasureStatus::cases()))->toBe([
        'deleted', 'anonymized', 'restricted', 'on_hold', 'start_event_pending', 'cancelled', 'no_policy',
    ]);
});
