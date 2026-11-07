<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItsJustVita\Retention\Audit\AuditSeverity;
use ItsJustVita\Retention\Audit\RetentionAudit;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Note;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\RestrictableProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

const AUDIT_MODELS = [Customer::class, Invoice::class, Order::class, ServerLog::class];

beforeEach(function () {
    config([
        'retention.log.key' => 'test-log-key',
        'retention.models.paths' => [],
        'retention.models.additional' => AUDIT_MODELS,
        'retention.exclusions.models' => [],
        'retention.exclusions.tables' => [
            'migrations' => 'Framework bookkeeping without personal data.',
            'notes' => 'Test notes without personal data.',
        ],
        'retention.tables' => [],
    ]);
});

afterEach(function () {
    foreach (['retained_children', 'order_lines', 'sessions', 'tags', 'nullable_children'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('passes when every model and table is covered', function () {
    $this->artisan('retention:audit')
        ->expectsOutputToContain('All models and tables are covered')
        ->assertSuccessful();
});

it('fails for a model without a policy or exclusion', function () {
    config(['retention.models.additional' => [...AUDIT_MODELS, Note::class]]);

    $this->artisan('retention:audit')
        ->expectsOutputToContain('['.Note::class.'] has no retention policy and no exclusion.')
        ->assertFailed();
});

it('accepts an excluded model, which also covers its table', function () {
    config([
        'retention.models.additional' => [...AUDIT_MODELS, Note::class],
        'retention.exclusions.models' => [Note::class => 'Contains no personal data.'],
        'retention.exclusions.tables' => ['migrations' => 'Framework bookkeeping.'],
    ]);

    $this->artisan('retention:audit')->assertSuccessful();
});

it('fails for a table without a model, policy or exclusion', function () {
    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->integer('last_activity');
    });

    $this->artisan('retention:audit')
        ->expectsOutputToContain('table [sessions] has no model with a retention policy, no table policy and no exclusion.')
        ->assertFailed();
});

it('accepts a table with a table policy', function () {
    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->integer('last_activity');
    });
    config(['retention.tables' => ['sessions' => [
        'category' => 'sessions',
        'label' => 'User sessions',
        'rule' => 'server_logs',
        'starts_at' => 'last_activity',
        'start_event' => 'collection',
        'responsible' => 'IT',
    ]]]);

    $this->artisan('retention:audit')->assertSuccessful();
});

it('reports invalid policies and table policies', function () {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('probe', 'Probe')->rule('no_such_rule');
    config([
        'retention.models.additional' => [...AUDIT_MODELS, PolicyProbe::class],
        'retention.tables' => ['no_such_table' => [
            'category' => 'x', 'label' => 'X', 'rule' => 'server_logs',
            'starts_at' => 'created_at', 'start_event' => 'collection', 'responsible' => 'IT',
        ]],
    ]);

    $this->artisan('retention:audit')
        ->expectsOutputToContain('['.PolicyProbe::class.']: unknown retention rule [no_such_rule].')
        ->expectsOutputToContain('table [no_such_table]: the table does not exist.')
        ->assertFailed();
});

it('reports missing package tables', function () {
    Schema::drop('retention_log');

    $this->artisan('retention:audit')
        ->expectsOutputToContain('table [retention_log] does not exist; publish and run the package migrations')
        ->assertFailed();
});

it('fails when deleting a parent would cascade into data that must be kept', function () {
    Schema::create('retained_children', function (Blueprint $table) {
        $table->id();
        $table->foreignId('order_id')->constrained()->cascadeOnDelete();
        $table->restrictable();
        $table->timestamps();
    });
    PolicyProbe::$probeTable = 'retained_children';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('children', 'Children')
        ->rule('ao_accounting_voucher')->startsAt('created_at', 'end_of_transaction')->action('delete')->responsible('Accounting');
    config(['retention.models.additional' => [...AUDIT_MODELS, RestrictableProbe::class]]);

    $this->artisan('retention:audit')
        ->expectsOutputToContain('deleting from [orders] cascades into [retained_children], whose rule [ao_accounting_voucher] requires keeping the rows. Use a restricting foreign key and blockedBy().')
        ->assertFailed();
});

it('warns about a foreign key that blockedBy() does not cover, without failing', function () {
    Schema::create('order_lines', function (Blueprint $table) {
        $table->id();
        $table->foreignId('order_id')->constrained();
    });
    config(['retention.exclusions.tables' => [
        ...config('retention.exclusions.tables'),
        'order_lines' => 'No personal data.',
    ]]);

    $this->artisan('retention:audit')
        ->expectsOutputToContain('[order_lines.order_id] references [orders], whose policy deletes rows, but blockedBy() does not cover [order_lines]; the deletion would fail on the constraint.')
        ->assertSuccessful();

    $this->artisan('retention:audit', ['--strict' => true])->assertFailed();
});

it('does not warn about foreign keys that set null or cascade into unretained rows', function () {
    Schema::create('nullable_children', function (Blueprint $table) {
        $table->id();
        $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
    });
    Schema::create('tags', function (Blueprint $table) {
        $table->id();
        $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    });
    config(['retention.exclusions.tables' => [
        ...config('retention.exclusions.tables'),
        'nullable_children' => 'No personal data.',
        'tags' => 'No personal data.',
    ]]);

    expect(app(RetentionAudit::class)->run()->findings)->toBe([]);
});

it('warns when the deletion log falls back to the application key', function () {
    config(['retention.log.key' => null]);

    $this->artisan('retention:audit')
        ->expectsOutputToContain('The deletion log uses APP_KEY')
        ->assertSuccessful();
});

it('fails for exclusions without a justification', function () {
    config(['retention.exclusions.tables' => [
        'migrations' => '',
        'notes',
    ]]);

    $this->artisan('retention:audit')
        ->expectsOutputToContain('exclusion [migrations] needs a justification')
        ->expectsOutputToContain('exclusion [notes] needs a justification')
        ->assertFailed();
});

it('warns about exclusions that no longer apply', function () {
    config([
        'retention.exclusions.models' => [Customer::class => 'Outdated.', 'App\Models\Gone' => 'Outdated.'],
        'retention.exclusions.tables' => [
            ...config('retention.exclusions.tables'),
            'no_such_table' => 'Outdated.',
        ],
    ]);

    $this->artisan('retention:audit')
        ->expectsOutputToContain('['.Customer::class.'] is excluded but also has a retention policy.')
        ->expectsOutputToContain('[App\Models\Gone] is excluded but is not a discovered model.')
        ->expectsOutputToContain('table [no_such_table] is excluded but does not exist.')
        ->assertSuccessful();
});

it('warns when one category has different labels', function () {
    PolicyProbe::$probeTable = 'server_logs';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('server_logs', 'Another label')
        ->rule('server_logs')->startsAt('logged_at', 'collection')->action('delete')->responsible('IT');
    config(['retention.models.additional' => [...AUDIT_MODELS, PolicyProbe::class]]);

    $this->artisan('retention:audit')
        ->expectsOutputToContain('category [server_logs] has different labels: [Another label], [Web server logs].')
        ->assertSuccessful();
});

it('returns the findings as a report', function () {
    config(['retention.models.additional' => [...AUDIT_MODELS, Note::class], 'retention.log.key' => null]);

    $report = app(RetentionAudit::class)->run();

    expect($report->hasErrors())->toBeTrue()
        ->and($report->errors())->toHaveCount(1)
        ->and($report->warnings())->toHaveCount(1)
        ->and($report->errors()[0]->severity)->toBe(AuditSeverity::Error)
        ->and($report->errors()[0]->section)->toBe('models')
        ->and($report->warnings()[0]->section)->toBe('log');
});
