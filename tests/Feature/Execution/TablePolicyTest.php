<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Events\TableRowsDeleted;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Models\RetentionLog;
use ItsJustVita\Retention\Policies\TablePolicy;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sessionsPolicy(array $overrides = []): array
{
    return array_replace([
        'category' => 'sessions',
        'label' => 'User sessions',
        'rule' => 'server_logs',
        'starts_at' => 'last_activity',
        'start_event' => 'collection',
        'responsible' => 'IT',
    ], $overrides);
}

function createSession(string $id, string $lastActivity): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'ip_address' => '192.0.2.1',
        'last_activity' => at($lastActivity)->getTimestamp(),
    ]);
}

beforeEach(function () {
    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('ip_address')->nullable();
        $table->integer('last_activity')->index();
    });

    config([
        'retention.timezone' => 'Europe/Berlin',
        'retention.log.key' => 'test-log-key',
        'retention.models.paths' => [],
        'retention.models.additional' => [ServerLog::class],
        'retention.tables' => ['sessions' => sessionsPolicy()],
    ]);
});

afterEach(fn () => Schema::dropIfExists('sessions'));

it('parses a table policy', function () {
    $policy = TablePolicy::fromArray('sessions', sessionsPolicy(['description' => 'Login sessions', 'connection' => 'audit']));

    expect($policy->table)->toBe('sessions')
        ->and($policy->category)->toBe('sessions')
        ->and($policy->label)->toBe('User sessions')
        ->and($policy->description)->toBe('Login sessions')
        ->and($policy->ruleKey)->toBe('server_logs')
        ->and($policy->startColumn)->toBe('last_activity')
        ->and($policy->startEvent)->toBe(StartEvent::Collection)
        ->and($policy->responsibleUnit)->toBe('IT')
        ->and($policy->connection)->toBe('audit');
});

it('rejects invalid table policies', function (array $overrides, string $message) {
    expect(fn () => TablePolicy::fromArray('sessions', sessionsPolicy($overrides)))
        ->toThrow(InvalidRetentionPolicy::class, $message);
})->with([
    'unknown key' => [['action' => 'anonymize'], 'unknown keys [action]'],
    'missing category' => [['category' => null], '[category] must be a non-empty string'],
    'missing rule' => [['rule' => ''], '[rule] must be a non-empty string'],
    'missing column' => [['starts_at' => null], '[starts_at] must be a non-empty string'],
    'missing responsible unit' => [['responsible' => null], '[responsible] must be a non-empty string'],
    'unknown start event' => [['start_event' => 'created'], '[start_event] must be one of [collection, end_of_transaction, end_of_relationship]'],
    'non-string connection' => [['connection' => 5], '[connection] must be a string'],
]);

it('deletes the due rows, also with a Unix timestamp column', function () {
    createSession('old-1', '2025-03-15 14:00');
    createSession('old-2', '2025-03-15 23:59');
    createSession('fresh', '2025-03-16 00:00');

    $this->travelTo(at('2025-03-22 23:59:59'));
    $this->artisan('retention:run')->assertSuccessful();
    expect(DB::table('sessions')->count())->toBe(3);

    $this->travelTo(at('2025-03-23 00:00:00'));
    $this->artisan('retention:run')
        ->expectsTable(['Category', 'Model', 'Deleted', 'Anonymised', 'Blocked', 'On hold', 'Skipped'], [
            ['server_logs', ServerLog::class, '0', '0', '0', '0', '0'],
            ['sessions', 'sessions', '2', '0', '0', '0', '0'],
        ])
        ->assertSuccessful();

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['fresh']);
});

it('deletes in batches and logs the number of rows without any row identity', function () {
    Event::fake([TableRowsDeleted::class]);
    foreach (range(1, 5) as $i) {
        createSession("old-{$i}", '2025-01-01 10:00');
    }
    $this->travelTo(at('2025-03-01'));

    $this->artisan('retention:run', ['--chunk' => 2])->assertSuccessful();

    expect(DB::table('sessions')->count())->toBe(0);

    Event::assertDispatchedTimes(TableRowsDeleted::class, 3);
});

it('writes one aggregated log entry per batch', function () {
    foreach (range(1, 3) as $i) {
        createSession("old-{$i}", '2025-01-01 10:00');
    }
    $this->travelTo(at('2025-03-01 04:00'));

    $this->artisan('retention:run', ['--chunk' => 2])->assertSuccessful();

    $entries = RetentionLog::query()->where('model', 'sessions')->orderBy('id')->get();

    expect($entries->sum('record_count'))->toBe(3)
        ->and($entries->pluck('record_hash')->unique()->all())->toBe([null])
        ->and($entries->pluck('key_id')->unique()->all())->toBe([null])
        ->and($entries->first()?->category)->toBe('sessions')
        ->and($entries->first()?->rule)->toBe('server_logs')
        ->and($entries->first()?->action)->toBe('deleted')
        ->and($entries->first()?->trigger)->toBe('schedule');
});

it('logs nothing when no rows were due', function () {
    createSession('fresh', '2025-03-01 10:00');
    $this->travelTo(at('2025-03-02'));

    $this->artisan('retention:run')->assertSuccessful();

    expect(RetentionLog::count())->toBe(0);
});

it('only counts in a dry run', function () {
    createSession('old', '2025-01-01 10:00');
    $this->travelTo(at('2025-03-01'));

    $this->artisan('retention:run', ['--dry-run' => true])
        ->expectsTable(['Category', 'Model', 'Due', 'Blocked', 'On hold'], [
            ['server_logs', ServerLog::class, '0', '0', '0'],
            ['sessions', 'sessions', '1', '0', '0'],
        ])
        ->assertSuccessful();

    expect(DB::table('sessions')->count())->toBe(1);
});

it('leaves table policies out when specific models are requested', function () {
    createSession('old', '2025-01-01 10:00');
    $this->travelTo(at('2025-03-01'));

    $this->artisan('retention:run', ['--model' => ['ServerLog']])
        ->expectsTable(['Category', 'Model', 'Deleted', 'Anonymised', 'Blocked', 'On hold', 'Skipped'], [
            ['server_logs', ServerLog::class, '0', '0', '0', '0', '0'],
        ])
        ->assertSuccessful();

    expect(DB::table('sessions')->count())->toBe(1);
});

it('shows table policies in retention:due', function () {
    createSession('old', '2025-01-01 10:00');
    createSession('soon', '2025-02-27 10:00');
    createSession('fresh', '2025-03-01 09:00');
    $this->travelTo(at('2025-03-01 10:00'));

    $this->artisan('retention:due', ['--days' => 2])
        ->expectsTable(['Category', 'Model', 'Due now', 'Blocked', 'On hold', 'Due within 2 days', 'Restricted'], [
            ['server_logs', ServerLog::class, '0', '0', '0', '0', '–'],
            ['sessions', 'sessions', '1', '0', '0', '0', '–'],
        ])
        ->assertSuccessful();

    $this->artisan('retention:due', ['--days' => 6])
        ->expectsTable(['Category', 'Model', 'Due now', 'Blocked', 'On hold', 'Due within 6 days', 'Restricted'], [
            ['server_logs', ServerLog::class, '0', '0', '0', '0', '–'],
            ['sessions', 'sessions', '1', '0', '0', '1', '–'],
        ])
        ->assertSuccessful();
});

it('refuses to run with an invalid table policy', function (array $overrides, string $message) {
    config(['retention.tables' => ['sessions' => sessionsPolicy($overrides)]]);
    createSession('old', '2025-01-01 10:00');
    $this->travelTo(at('2025-03-01'));

    $this->artisan('retention:run')
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(DB::table('sessions')->count())->toBe(1);
})->with([
    'unknown rule' => [['rule' => 'no_such_rule'], 'table [sessions]: unknown retention rule [no_such_rule].'],
    'missing column' => [['starts_at' => 'expires_at'], 'table [sessions]: start column [expires_at] does not exist.'],
    'unsupported column type' => [['starts_at' => 'ip_address'], 'table [sessions]: start column [ip_address] has type'],
    'malformed' => [['label' => null], '[label] must be a non-empty string'],
]);

it('refuses to run when the table does not exist', function () {
    config(['retention.tables' => ['no_such_table' => sessionsPolicy()]]);

    $this->artisan('retention:run')
        ->expectsOutputToContain('table [no_such_table]: the table does not exist.')
        ->assertFailed();
});
