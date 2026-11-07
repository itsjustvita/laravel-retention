<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ItsJustVita\Retention\Actions\ActionExecutor;
use ItsJustVita\Retention\Enums\ActionOutcome;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Events\RecordAnonymized;
use ItsJustVita\Retention\Events\RecordDeleted;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\CustomerAnonymizer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

function executor(): ActionExecutor
{
    return app(ActionExecutor::class);
}

it('hard-deletes a record, fires model events and dispatches RecordDeleted', function () {
    Event::fake([RecordDeleted::class]);
    $log = ServerLog::create(['ip_address' => '192.0.2.1', 'logged_at' => now()]);
    $deletedEvents = 0;
    ServerLog::deleted(function () use (&$deletedEvents) {
        $deletedEvents++;
    });

    expect(executor()->apply($log, Trigger::Schedule))->toBe(ActionOutcome::Deleted)
        ->and(DB::table('server_logs')->count())->toBe(0)
        ->and($deletedEvents)->toBe(1);

    Event::assertDispatched(RecordDeleted::class, fn (RecordDeleted $event) => $event->model->is($log)
        && $event->trigger === Trigger::Schedule
        && $event->policy->category === 'server_logs');
});

it('force-deletes soft-deleting models, including restricted and trashed records', function () {
    $order = createOrder(createCustomer());
    $order->restrict();
    $order->delete();

    $record = Order::withTrashed()->withRestricted()->findOrFail($order->id);

    expect(executor()->apply($record, Trigger::Schedule))->toBe(ActionOutcome::Deleted)
        ->and(DB::table('orders')->count())->toBe(0);
});

it('reports a deletion cancelled by a model event', function () {
    Event::fake([RecordDeleted::class]);
    $log = ServerLog::create(['ip_address' => '192.0.2.1', 'logged_at' => now()]);
    ServerLog::deleting(fn () => false);

    expect(executor()->apply($log, Trigger::Schedule))->toBe(ActionOutcome::Cancelled)
        ->and(DB::table('server_logs')->count())->toBe(1);

    Event::assertNotDispatched(RecordDeleted::class);
});

it('anonymises a record, marks it and lifts its restriction', function () {
    Event::fake([RecordAnonymized::class]);
    $this->travelTo(at('2029-01-01 03:00'));
    $customer = createCustomer();
    $customer->restrict();

    expect(executor()->apply($customer, Trigger::Schedule))->toBe(ActionOutcome::Anonymized);

    $row = DB::table('customers')->find($customer->id);

    expect($row->name)->toBe('deleted')
        ->and($row->email)->toBeNull()
        ->and($row->phone)->toBeNull()
        ->and($row->anonymized_at)->not->toBeNull()
        ->and($row->restricted_at)->toBeNull()
        ->and($customer->isDirty())->toBeFalse();

    Event::assertDispatched(RecordAnonymized::class, fn (RecordAnonymized $event) => $event->model->is($customer)
        && $event->trigger === Trigger::Schedule);
});

it('anonymises only once', function () {
    Event::fake([RecordAnonymized::class]);
    $customer = createCustomer();
    executor()->apply($customer, Trigger::Schedule);
    Event::fake([RecordAnonymized::class]);

    $customer->forceFill(['name' => 'Changed later'])->save();

    expect(executor()->apply($customer->fresh() ?? $customer, Trigger::Schedule))->toBe(ActionOutcome::AlreadyAnonymized)
        ->and(DB::table('customers')->value('name'))->toBe('Changed later');

    Event::assertNotDispatched(RecordAnonymized::class);
});

it('resolves every form of anonymizer', function (Closure|string $anonymizer) {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('probe', 'Probe')
        ->rule('server_logs')
        ->startsAt('created_at', 'collection')
        ->action('anonymize')
        ->anonymizeUsing($anonymizer)
        ->responsible('IT');

    $probe = PolicyProbe::query()->findOrFail(createCustomer()->id);

    expect(executor()->apply($probe, Trigger::Manual))->toBe(ActionOutcome::Anonymized)
        ->and(DB::table('customers')->value('name'))->toBe('deleted');
})->with([
    'closure' => fn () => fn (PolicyProbe $probe) => $probe->forceFill(['name' => 'deleted'])->saveQuietly(),
    'invokable class' => fn () => (new class
    {
        public function __invoke(PolicyProbe $probe): void
        {
            $probe->forceFill(['name' => 'deleted'])->saveQuietly();
        }
    })::class,
    'method on the model' => 'anonymizeForRetention',
]);

it('resolves an invokable anonymizer class from the container', function () {
    $customer = createCustomer();
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('probe', 'Probe')
        ->rule('server_logs')->startsAt('created_at', 'collection')->action('anonymize')
        ->anonymizeUsing(CustomerAnonymizer::class)->responsible('IT');

    app()->bind(CustomerAnonymizer::class, fn () => new class
    {
        public function __invoke(PolicyProbe $probe): void
        {
            $probe->forceFill(['name' => 'from container'])->saveQuietly();
        }
    });

    executor()->apply(PolicyProbe::query()->findOrFail($customer->id), Trigger::Manual);

    expect(DB::table('customers')->value('name'))->toBe('from container');
});

it('does not delete a record while blockedBy() relations hold records', function (Closure $prepareInvoice) {
    Event::fake([RecordDeleted::class]);
    $customer = createCustomer();
    $order = createOrder($customer);
    $prepareInvoice(createInvoice($customer, ['order_id' => $order->id]));

    expect(executor()->apply($order, Trigger::Schedule))->toBe(ActionOutcome::Blocked)
        ->and(DB::table('orders')->count())->toBe(1);

    Event::assertNotDispatched(RecordDeleted::class);
})->with([
    'active invoice' => fn () => fn (Invoice $invoice) => null,
    'restricted invoice' => fn () => fn (Invoice $invoice) => $invoice->restrict(),
    'soft-deleted invoice' => fn () => fn (Invoice $invoice) => $invoice->delete(),
]);

it('deletes the record once the blocking relations are empty', function () {
    $customer = createCustomer();
    $order = createOrder($customer);
    $invoice = createInvoice($customer, ['order_id' => $order->id]);

    executor()->apply($invoice, Trigger::Schedule);

    expect(executor()->apply($order, Trigger::Schedule))->toBe(ActionOutcome::Deleted);
});
