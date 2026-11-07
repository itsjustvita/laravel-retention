<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Policies\PolicyValidator;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\CustomerAnonymizer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\CycleA;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\RestrictableProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

/**
 * Points PolicyProbe at a table and a policy, starting from a valid
 * declaration that `$adjust` can break.
 *
 * @param  (Closure(RetentionPolicy): RetentionPolicy)|null  $adjust
 * @return class-string<PolicyProbe>
 */
function probe(?Closure $adjust = null, string $table = 'customers', ?Closure $base = null): string
{
    PolicyProbe::$probeTable = $table;
    PolicyProbe::$policy = function () use ($adjust, $base): RetentionPolicy {
        $policy = $base !== null ? $base() : RetentionPolicy::category('probe', 'Probe')
            ->rule('server_logs')
            ->startsAt('created_at', StartEvent::Collection)
            ->action('delete')
            ->responsible('IT');

        return $adjust !== null ? $adjust($policy) : $policy;
    };

    return PolicyProbe::class;
}

it('accepts valid policies', function (string $model) {
    expect(app(PolicyValidator::class)->violations($model))->toBe([]);
})->with([
    'method, anonymize, blocking rule' => Customer::class,
    'attribute, delete, inherited holds' => Invoice::class,
    'delete blocked by a relation' => Order::class,
    'non-blocking rule' => ServerLog::class,
]);

it('accepts every form of anonymizer', function (Closure|string $anonymizer) {
    $model = probe(fn (RetentionPolicy $p) => $p->action('anonymize')->anonymizeUsing($anonymizer));

    expect(app(PolicyValidator::class)->violations($model))->toBe([]);
})->with([
    'closure' => fn () => fn () => null,
    'invokable class' => CustomerAnonymizer::class,
    'method on the model' => 'anonymizeForRetention',
]);

it('reports invalid policies', function (Closure $adjust, string $table, string $message) {
    expect(app(PolicyValidator::class)->violations(probe($adjust, $table)))
        ->toContain($message);
})->with([
    'unknown rule' => [
        fn (RetentionPolicy $p) => $p->rule('no_such_rule'),
        'customers',
        'unknown retention rule [no_such_rule].',
    ],
    'start column missing on the table' => [
        fn (RetentionPolicy $p) => $p->startsAt('signed_at', StartEvent::Collection),
        'customers',
        'start column [signed_at] does not exist on table [customers].',
    ],
    'start column of an unsupported type' => [
        fn (RetentionPolicy $p) => $p->startsAt('name', StartEvent::Collection),
        'customers',
        'start column [name] has type [varchar]; expected a date, datetime/timestamp or integer (Unix timestamp) column.',
    ],
    'anonymize without anonymizer' => [
        fn (RetentionPolicy $p) => $p->action('anonymize'),
        'customers',
        'action anonymize requires an anonymizer; call anonymizeUsing().',
    ],
    'anonymizer class that is not invokable' => [
        fn (RetentionPolicy $p) => $p->action('anonymize')->anonymizeUsing(stdClass::class),
        'customers',
        'anonymizer [stdClass] is not invokable; add an __invoke() method.',
    ],
    'anonymizer that is neither class nor method' => [
        fn (RetentionPolicy $p) => $p->action('anonymize')->anonymizeUsing('scrub'),
        'customers',
        'anonymizer [scrub] is neither an invokable class nor a method of the model.',
    ],
    'anonymize without anonymized_at column' => [
        fn (RetentionPolicy $p) => $p->startsAt('logged_at', StartEvent::Collection)->action('anonymize')->anonymizeUsing('anonymizeForRetention'),
        'server_logs',
        'column [anonymized_at] does not exist on table [server_logs]; add it with $table->anonymizable(), required by action anonymize.',
    ],
    'blocking rule without restricted_at column' => [
        fn (RetentionPolicy $p) => $p->rule('bgb_limitation_period')->startsAt('logged_at', StartEvent::Collection),
        'server_logs',
        'column [restricted_at] does not exist on table [server_logs]; add it with $table->restrictable(), required because rule [bgb_limitation_period] blocks erasure.',
    ],
    'blocking rule without the Restrictable trait' => [
        fn (RetentionPolicy $p) => $p->rule('bgb_limitation_period'),
        'customers',
        'rule [bgb_limitation_period] blocks erasure, so the model must use the Restrictable trait.',
    ],
    'unknown relation in blockedBy()' => [
        fn (RetentionPolicy $p) => $p->blockedBy('payments'),
        'customers',
        'blockedBy(): relation [payments] does not exist on the model.',
    ],
    'non-relation method in blockedBy()' => [
        fn (RetentionPolicy $p) => $p->blockedBy('notARelation'),
        'customers',
        'blockedBy(): [notARelation] is not an Eloquent relation.',
    ],
    'blockedBy() with anonymize' => [
        fn (RetentionPolicy $p) => $p->action('anonymize')->anonymizeUsing('anonymizeForRetention')->blockedBy('invoices'),
        'customers',
        'blockedBy() only applies to action delete; an anonymised record keeps its keys.',
    ],
    'unknown relation in inheritsHoldsFrom()' => [
        fn (RetentionPolicy $p) => $p->inheritsHoldsFrom('account'),
        'customers',
        'inheritsHoldsFrom(): relation [account] does not exist on the model.',
    ],
    'inheritsHoldsFrom() leading to a model without HasRetention' => [
        fn (RetentionPolicy $p) => $p->inheritsHoldsFrom('owner'),
        'customers',
        'inheritsHoldsFrom(): [owner] leads to [Illuminate\\Foundation\\Auth\\User], which does not use HasRetention and cannot carry legal holds.',
    ],
    'unknown nested relation in inheritsHoldsFrom()' => [
        fn (RetentionPolicy $p) => $p->inheritsHoldsFrom('invoices.payments'),
        'customers',
        'inheritsHoldsFrom(): relation [payments] does not exist on the model.',
    ],
    'missing table' => [
        fn (RetentionPolicy $p) => $p,
        'no_such_table',
        'table [no_such_table] does not exist; run the migrations before validating.',
    ],
]);

it('requires the restricted_at column for every model with the Restrictable trait', function () {
    probe(table: 'server_logs', adjust: fn (RetentionPolicy $p) => $p->startsAt('logged_at', StartEvent::Collection));

    expect(app(PolicyValidator::class)->violations(RestrictableProbe::class))->toBe([
        'column [restricted_at] does not exist on table [server_logs]; add it with $table->restrictable(), required by the Restrictable trait.',
    ]);
});

it('reports missing required parts', function (string $message) {
    $model = probe(base: fn () => RetentionPolicy::category('probe', 'Probe'));

    expect(app(PolicyValidator::class)->violations($model))->toContain($message);
})->with([
    'no rule set; call rule().',
    'no start column set; call startsAt().',
    'no action set; call action() with delete or anonymize.',
    'no responsible unit set; call responsible().',
]);

it('reports errors thrown while building the policy', function () {
    $model = probe(fn (RetentionPolicy $p) => $p->action('remove'));

    expect(app(PolicyValidator::class)->violations($model))
        ->toBe(['Invalid retention policy for [probe]: unknown action [remove]; expected one of [delete, anonymize].']);
});

it('reports exceptions thrown by retentionPolicy()', function () {
    PolicyProbe::$policy = null; // retentionPolicy() throws a LogicException

    expect(app(PolicyValidator::class)->violations(PolicyProbe::class))
        ->toBe(['retentionPolicy() threw LogicException: No probe policy set.']);
});

it('reports models without a policy', function () {
    expect(app(PolicyValidator::class)->violations(User::class))
        ->toBe(['['.User::class.'] has no retention policy. Use the HasRetention trait and define retentionPolicy() or add a #[Retention] attribute.']);
});

it('detects cycles in blockedBy()', function () {
    expect(app(PolicyValidator::class)->violations(CycleA::class))
        ->toContain('blockedBy() forms a cycle, so none of these records can ever be deleted: CycleA.bs → CycleB.as → CycleA.');
});

it('throws all violations at once', function () {
    $model = probe(fn (RetentionPolicy $p) => $p->rule('no_such_rule')->blockedBy('payments'));

    expect(fn () => app(PolicyValidator::class)->validate($model))
        ->toThrow(function (InvalidRetentionPolicy $e) use ($model) {
            expect($e->subject)->toBe($model)
                ->and($e->violations)->toHaveCount(3)
                ->and($e->getMessage())->toContain("Invalid retention policy for [{$model}]:\n- unknown retention rule [no_such_rule].");
        });
});

it('does not throw for a valid policy', function () {
    app(PolicyValidator::class)->validate(Customer::class);
})->throwsNoExceptions();
