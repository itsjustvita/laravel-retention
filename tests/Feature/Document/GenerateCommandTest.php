<?php

declare(strict_types=1);

use Barryvdh\DomPDF\ServiceProvider as DompdfServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use ItsJustVita\Retention\Document\GitInfo;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\RetentionServiceProvider;
use ItsJustVita\Retention\Rules\RuleCatalogue;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Note;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use ItsJustVita\Retention\Tests\Fixtures\Models\ServerLog;

/**
 * Runs retention:generate into a temporary file and returns the document.
 *
 * @param  array<string, mixed>  $options
 */
function generate(array $options = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'concept');

    expect(Artisan::call('retention:generate', ['--as-of' => '2026-11-07', '--output' => $path, ...$options]))->toBe(0);

    $document = (string) file_get_contents($path);
    unlink($path);

    return $document;
}

/**
 * Replaces GitInfo so that documents do not depend on the real repository.
 *
 * @param  array{hash: string, date: CarbonImmutable}|null  $commit
 */
function fakeGit(?array $commit): void
{
    app()->instance(GitInfo::class, new class($commit) extends GitInfo
    {
        /** @param array{hash: string, date: CarbonImmutable}|null $commit */
        public function __construct(private readonly ?array $commit)
        {
            parent::__construct('.');
        }

        public function lastCommit(): ?array
        {
            return $this->commit;
        }
    });
}

beforeEach(function () {
    Schema::create('sessions', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->integer('last_activity')->index();
    });

    fakeGit(null);

    config([
        'retention.timezone' => 'Europe/Berlin',
        'retention.models.paths' => [],
        'retention.models.additional' => [Customer::class, Invoice::class, Order::class, ServerLog::class],
        'retention.tables' => ['sessions' => [
            'category' => 'sessions',
            'label' => 'User sessions',
            'description' => 'Login sessions of the web application.',
            'rule' => 'server_logs',
            'starts_at' => 'last_activity',
            'start_event' => 'collection',
            'responsible' => 'IT',
        ]],
        'retention.exclusions.models' => [Note::class => 'Contains no personal data.'],
        'retention.exclusions.tables' => ['migrations' => 'Framework bookkeeping without personal data.'],
        'retention.document' => [
            'organisation' => 'Beispiel GmbH',
            'controller' => 'Beispiel GmbH, Musterstraße 1, 10115 Berlin',
            'data_protection_officer' => 'Erika Datenschutz, dsb@example.com',
            'version' => '1.2',
            'purpose' => null,
            'systems_not_covered' => null,
        ],
    ]);
});

afterEach(fn () => Schema::dropIfExists('sessions'));

it('generates the complete document', function (string $format, string $locale) {
    expect(generate(['--format' => $format, '--locale' => $locale]))->toMatchSnapshot();
})->with(['md', 'html'])->with(['en', 'de']);

it('contains the DIN 66398 sections in order', function () {
    $document = generate(['--format' => 'md', '--locale' => 'en']);

    $positions = array_map(fn (string $heading) => strpos($document, $heading), [
        '# Deletion concept',
        '## 1. Purpose and scope',
        '## 2. Data categories',
        '## 3. Deletion class matrix',
        '## 4. Deletion rules per data category',
        '## 5. Implementation',
        '## 6. Exclusions',
        '## 7. Systems not covered',
    ]);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(array_values(array_unique($positions)))
        ->and($positions)->toEqual(collect($positions)->sort()->values()->all());
});

it('shows organisation, controller, data protection officer and version on the cover', function () {
    expect(generate(['--format' => 'md', '--locale' => 'en']))
        ->toContain('Beispiel GmbH, Musterstraße 1, 10115 Berlin')
        ->toContain('Erika Datenschutz, dsb@example.com')
        ->toContain('| Version | 1.2 |')
        ->toContain('| As of | 2026-11-07 |');
});

it('sorts the matrix by period and marks end-of-year counting', function () {
    $document = generate(['--format' => 'md', '--locale' => 'en']);

    expect(strpos($document, '| 7 days |'))->toBeLessThan(strpos($document, '| 3 years |'))
        ->and(strpos($document, '| 3 years |'))->toBeLessThan(strpos($document, '| 8 years |'))
        ->and($document)->toContain('| 8 years |  | Customer invoices* |  |')
        ->and($document)->toContain('| 3 years |  | Orders* | Customer master data* |')
        ->and($document)->toContain('| 7 days | User sessions, Web server logs |  |  |')
        ->and($document)->toContain('* Counted from the end of the calendar year in which the start event occurred.');
});

it('describes dependencies, inherited holds and rule transitions', function () {
    expect(generate(['--format' => 'md', '--locale' => 'en']))
        ->toContain('Deleted only once these relations are empty: invoices')
        ->toContain('Legal holds inherited from: customer, order')
        ->toContain('Until 2025-01-01: 10 years')
        ->toContain('Reduced from 10 to 8 years');
});

it('lists exclusions with their justification', function () {
    expect(generate(['--format' => 'md', '--locale' => 'en']))
        ->toContain('| '.Note::class.' | Contains no personal data. |')
        ->toContain('| migrations | Framework bookkeeping without personal data. |');
});

it('uses German legal terms in the German document, unless the rule was overridden', function () {
    expect(generate(['--format' => 'md', '--locale' => 'de']))
        ->toContain('§ 147 Abs. 1 Nr. 4, Abs. 3, 4 AO')
        ->toContain('Art. 17 Abs. 3 lit. b DSGVO')
        ->toContain('8 Jahre');

    config(['retention.rules' => ['ao_accounting_voucher' => ['legal_basis' => 'Own legal assessment']]]);
    app()->forgetInstance(RuleCatalogue::class);

    expect(generate(['--format' => 'md', '--locale' => 'de']))
        ->toContain('Own legal assessment')
        ->not->toContain('§ 147 Abs. 1 Nr. 4, Abs. 3, 4 AO');
});

it('uses the configured texts instead of the boilerplate', function () {
    config([
        'retention.document.purpose' => 'Our own purpose text.',
        'retention.document.systems_not_covered' => ['en' => 'Backups rotate after 30 days.', 'de' => 'Backups rotieren nach 30 Tagen.'],
    ]);

    expect(generate(['--format' => 'md', '--locale' => 'en']))
        ->toContain('Our own purpose text.')
        ->toContain('Backups rotate after 30 days.')
        ->not->toContain('The application only sees its own database');

    expect(generate(['--format' => 'md', '--locale' => 'de']))
        ->toContain('Backups rotieren nach 30 Tagen.');
});

it('prefills a note on backups', function () {
    expect(generate(['--format' => 'md', '--locale' => 'en']))->toContain('Backups');
});

it('describes the deletion log according to the configuration', function () {
    config(['retention.log.retention' => 'P2Y']);
    expect(generate(['--format' => 'md', '--locale' => 'en']))
        ->toContain('pseudonymised')
        ->toContain('kept for 2 years');

    config(['retention.log.enabled' => false]);
    expect(generate(['--format' => 'md', '--locale' => 'en']))
        ->toContain('The deletion log is disabled.')
        ->not->toContain('kept for 2 years');
});

it('produces byte-identical output for the same declarations and as-of date', function () {
    $this->travelTo(at('2026-01-01'));
    $first = generate(['--format' => 'md', '--locale' => 'en']);

    $this->travelTo(at('2030-06-15 13:37'));
    config(['retention.models.additional' => [ServerLog::class, Order::class, Customer::class, Invoice::class]]);
    $second = generate(['--format' => 'md', '--locale' => 'en']);

    expect($second)->toBe($first);
});

it('takes the as-of date and hash from the last Git commit', function () {
    fakeGit(['hash' => 'abcdef1234567890abcdef1234567890abcdef12', 'date' => CarbonImmutable::parse('2026-10-31T23:30:00+00:00')]);

    expect(generate(['--as-of' => null, '--format' => 'md', '--locale' => 'en']))
        ->toContain('| As of | 2026-11-01 |') // 23:30 UTC is already 1 November in Berlin
        ->toContain('| Commit | abcdef123456 |');
});

it('falls back to the generation date without Git', function () {
    $this->travelTo(at('2026-05-04 12:00'));

    expect(generate(['--as-of' => null, '--format' => 'md', '--locale' => 'en']))
        ->toContain('| As of | 2026-05-04 |')
        ->not->toContain('| Commit |');
});

it('prefers --as-of over the Git commit and leaves the hash out', function () {
    fakeGit(['hash' => 'abcdef1234567890abcdef1234567890abcdef12', 'date' => CarbonImmutable::parse('2026-10-31T12:00:00+00:00')]);

    expect(generate(['--as-of' => '2026-11-07', '--format' => 'md', '--locale' => 'en']))
        ->toContain('| As of | 2026-11-07 |')
        ->not->toContain('abcdef123456');
});

it('escapes table cells in Markdown and everything in HTML', function () {
    config(['retention.tables.sessions.label' => 'Sessions | <script>alert(1)</script>']);

    expect(generate(['--format' => 'md', '--locale' => 'en']))->toContain('Sessions \| ');

    expect(generate(['--format' => 'html', '--locale' => 'en']))
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>');
});

it('prints the document without --output', function () {
    Artisan::call('retention:generate', ['--as-of' => '2026-11-07', '--format' => 'md', '--locale' => 'en']);

    expect(Artisan::output())->toContain('# Deletion concept');
});

it('warns about missing cover data', function () {
    config(['retention.document.controller' => null]);
    $path = tempnam(sys_get_temp_dir(), 'concept');

    $this->artisan('retention:generate', ['--as-of' => '2026-11-07', '--output' => $path])
        ->expectsOutputToContain('retention.document.controller is not set')
        ->assertSuccessful();

    unlink($path);
});

it('refuses to generate while a policy is invalid', function () {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('probe', 'Probe')->rule('no_such_rule');
    config(['retention.models.additional' => [PolicyProbe::class]]);
    $path = sys_get_temp_dir().'/concept-'.uniqid().'.md';

    $this->artisan('retention:generate', ['--output' => $path])
        ->expectsOutputToContain('unknown retention rule [no_such_rule].')
        ->assertFailed();

    expect(file_exists($path))->toBeFalse();
});

it('rejects invalid options', function (array $options, string $message) {
    $this->artisan('retention:generate', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'format' => [['--format' => 'docx'], '--format must be one of md, html, pdf.'],
    'locale' => [['--locale' => 'fr'], '--locale must be one of en, de.'],
    'as-of' => [['--as-of' => '07.11.2026'], '--as-of must be a date in the format YYYY-MM-DD.'],
    'pdf without output' => [['--format' => 'pdf'], '--format=pdf needs --output.'],
]);

it('hints at printing the HTML when the PDF package is missing', function () {
    $this->artisan('retention:generate', ['--format' => 'pdf', '--output' => sys_get_temp_dir().'/concept.pdf'])
        ->expectsOutputToContain('composer require barryvdh/laravel-dompdf')
        ->assertFailed();
});

it('exports a PDF when the package is installed', function () {
    app()->register(DompdfServiceProvider::class);

    $document = generate(['--format' => 'pdf', '--locale' => 'de']);

    expect(substr($document, 0, 5))->toBe('%PDF-');
});

it('makes the templates and translations publishable', function () {
    expect(ServiceProvider::pathsToPublish(RetentionServiceProvider::class, 'retention-views'))->toHaveCount(1)
        ->and(ServiceProvider::pathsToPublish(RetentionServiceProvider::class, 'retention-translations'))->toHaveCount(1);
});
