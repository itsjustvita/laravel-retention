# laravel-retention

Declare retention and deletion rules directly on your Eloquent models. The package enforces them automatically (delete or anonymise, with restriction while erasure is blocked) and generates a deletion concept ("Löschkonzept", structured according to DIN 66398) from the very same declarations, so the document and the actual behaviour cannot drift apart.

> [!CAUTION]
> **This package is not legal advice.** The shipped rule catalogue, the generated document and the reply texts for data subjects are starting points. They may be incomplete, outdated or wrong for your organisation. The controller has to review every rule and text with a data protection officer or a lawyer before relying on them. Deleting data is irreversible, and keeping it too long is a compliance risk, so try `retention:run --dry-run` first. The software is provided "as is", without warranty of any kind and without liability for damages; see the [license](LICENSE.md).

> [!IMPORTANT]
> **Early release (0.x).** The legal content has not been reviewed by a data protection officer or a lawyer yet, so the shipped rules carry no `as_of` date, and the API may change before 1.0. Known open points:
>
> - DIN 66398 has been replaced by DIN EN ISO/IEC 27555:2025-09. The generated document still refers to DIN 66398 and has not been compared with the new standard.
> - The `ao_*` rules cite § 35 (3) BDSG, although its wording only covers retention periods from statutes of an organisation or from contracts, not statutory ones.
> - `rejected_application`, `server_logs` and `consent_proof` are recommendations without a statutory period.
> - The automatic deletion cannot detect an open tax assessment (§ 147 (3) AO); see [How periods are counted](#rule-catalogue).

Unlike generic retention packages, it knows:

- German statutory retention periods (AO, HGB, UStG, BGB limitation periods) and that they start at the end of the calendar year,
- restriction instead of deletion under Art. 18 GDPR / § 35 BDSG while a retention duty is still running,
- legal holds, erasure requests under Art. 17 GDPR, and a deletion log as evidence under Art. 5 (2) GDPR,
- how to stop new tables from shipping without a deletion rule (`retention:audit`).

## Requirements

- PHP 8.4+
- Laravel 13
- SQLite, MySQL, MariaDB or PostgreSQL

## Installation

```bash
composer require itsjustvita/laravel-retention

php artisan vendor:publish --tag=retention-config
php artisan vendor:publish --tag=retention-migrations
php artisan migrate
```

Add a dedicated key for the deletion log to your `.env` (see [Deletion log](#deletion-log)):

```dotenv
RETENTION_LOG_KEY=a-long-random-string   # e.g. from: openssl rand -hex 32
RETENTION_TIMEZONE=Europe/Berlin
```

The package does not schedule itself. Add the run to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('retention:run')->daily();
```

## Quickstart

**1. Add the columns.** `restrictable()` adds `restricted_at`, `anonymizable()` adds `anonymized_at`. Both are nullable and indexed; `dropRestrictable()` and `dropAnonymizable()` remove them again.

```php
Schema::table('customers', function (Blueprint $table) {
    $table->restrictable();  // needed when the rule blocks erasure or the policy uses blockedBy()
    $table->anonymizable();  // needed for action('anonymize')
});

Schema::table('invoices', function (Blueprint $table) {
    $table->restrictable();
});
```

**2. Declare the policies.**

```php
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Concerns\Restrictable;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\RetentionPolicy;

class Invoice extends Model
{
    use HasRetention, Restrictable;

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('invoices', 'Customer invoices')
            ->rule('ao_accounting_voucher')                       // 8 years from the end of the calendar year
            ->startsAt('issued_at', StartEvent::EndOfTransaction) // the column holding the start event
            ->action('delete')
            ->inheritsHoldsFrom('customer')                       // a hold on the customer protects the invoice
            ->responsible('Accounting');
    }
}

class Customer extends Model
{
    use HasRetention, Restrictable;

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('customer_master_data', 'Customer master data')
            ->rule('bgb_limitation_period')
            ->startsAt('contract_ended_at', StartEvent::EndOfRelationship) // null = period not running yet
            ->action('anonymize')
            ->anonymizeUsing(fn (Customer $customer) => $customer->forceFill([
                'name' => 'deleted',
                'email' => null,
            ])->saveQuietly())
            ->responsible('Sales');
    }
}
```

**3. See where you stand.**

```bash
php artisan retention:audit            # what has no deletion rule yet?
php artisan retention:due              # what is due, blocked, on hold, coming up?
php artisan retention:run --dry-run    # what would a run do?
php artisan retention:run
php artisan retention:generate --locale=de --format=html --output=storage/loeschkonzept.html
```

> [!NOTE]
> `retention:audit` fails in a fresh Laravel application, and that is intended. Only `migrations`, `cache` and `cache_locks` are excluded by default. You decide what happens with `users`, `sessions`, `jobs`, `failed_jobs`, `job_batches` and `password_reset_tokens`; see [Audit](#audit).

## Rule catalogue

| Key | Period | Counted from | Blocks erasure | Basis |
|---|---|---|---|---|
| `ao_accounting_voucher` | 8 years | end of year | yes, Art. 17 (3) (b) | § 147 AO, § 257 HGB, § 14b UStG (since BEG IV) |
| `ao_books_financial_statements` | 10 years | end of year | yes, Art. 17 (3) (b) | § 147 (1) no. 1 AO, § 257 HGB |
| `ao_business_correspondence` | 6 years | end of year | yes, Art. 17 (3) (b) | § 147 (1) nos. 2, 3, 5 AO, § 257 HGB |
| `bgb_limitation_period` | 3 years | end of year | yes, Art. 17 (3) (e) | §§ 195, 199 BGB |
| `rejected_application` | 6 months | event | yes, Art. 17 (3) (e) | Recommendation based on § 15 (4) AGG, § 61b ArbGG |
| `server_logs` | 7 days | event | no | Recommendation |
| `consent_proof` | 3 years | end of year | yes, Art. 17 (3) (e) | Recommendation, Art. 7 (1) GDPR |
| `uwg_telephone_advertising_consent` | 5 years | event | yes, Art. 17 (3) (b) | § 7a UWG |

The catalogue ships with the package, so corrections reach you with an update and show up as a diff in your document. Override single keys or add your own rules in `config/retention.php`:

```php
'rules' => [
    // Institutions under the KWG, VAG or WpIG keep 10 years for vouchers:
    'ao_accounting_voucher' => [
        'period' => 'P10Y',
        'valid_from' => null,
        'previous' => null,
        'note' => 'Institution under the KWG: Art. 97 § 19a (3) EGAO.',
    ],

    'newsletter_tracking' => [
        'label' => 'Newsletter tracking data',
        'period' => 'P1Y',                  // ISO 8601: P8Y, P6M, P7D or PT72H
        'counting' => 'event',              // event | end_of_year
        'legal_basis' => 'Art. 6 (1) (a) GDPR',
        'type' => 'custom',                 // legal | recommendation | custom
        'blocks_erasure' => false,          // true also needs erasure_ground; erasure_reason is optional
    ],
],
```

**How periods are counted.** With `end_of_year`, an invoice dated 2025-03-15 with 8 years is deletable from 2034-01-01. With `event`, date-based periods follow §§ 187, 188 BGB: a rejection on 2025-03-15 with 6 months is deletable from 2025-09-16. Time-based periods like `PT72H` are exact. All calendar calculations use `RETENTION_TIMEZONE` (default: the application timezone).

**When a rule changes** (`valid_from`, `previous`), the rule in force on the evaluation date applies to every record that had not already expired under the previous version. This matches BEG IV: on 2025-01-01, vouchers from 2015 and 2016 became deletable at once. Institutions under the KWG, insurers under the VAG and securities institutions under the WpIG are excepted and keep 10 years for vouchers (Art. 97 § 19a (3) EGAO, § 257 (4) HGB), so they have to override the rule as shown above.

> [!WARNING]
> **Tax records can stay longer than their period.** Under § 147 (3) AO, the period does not expire while the records matter for taxes whose assessment period is still open. `retention:run` cannot detect this and deletes when the period ends. Agree on a process with your tax adviser and place a [legal hold](#legal-hold) on records that are affected.

**Why a rule keeps data.** `erasure_reason` decides the wording of the reply to a data subject: `legal_duty` ("we are legally required to keep"), `legal_claims` ("to establish, exercise or defend legal claims"), or none for a neutral sentence.

## Policies

Start columns may be `date`, `datetime`/`timestamp`, `timestamptz` or an integer Unix timestamp. While the start column is `null`, no period is running.

As an alternative to the method, use the attribute. Attributes cannot hold closures, so the anonymiser is an invokable class or a method of the model:

```php
use ItsJustVita\Retention\Attributes\Retention;

#[Retention(
    category: 'customer_master_data',
    label: 'Customer master data',
    rule: 'bgb_limitation_period',
    startsAt: 'contract_ended_at',
    startEvent: StartEvent::EndOfRelationship,
    action: 'anonymize',
    responsible: 'Sales',
    anonymizer: CustomerAnonymizer::class,
)]
class Customer extends Model
{
    use HasRetention, Restrictable;
}
```

Policies are validated in `retention:audit` and before `retention:run`, `retention:due` and `retention:generate`, which then stop before changing anything. In your own Pest tests, no setup needed:

```php
expect(Invoice::class)->toHaveValidRetentionPolicy();
```

With PHPUnit, call `app(PolicyValidator::class)->validate(Invoice::class)`.

## Actions and restriction

- **`delete`** hard-deletes the record (`forceDelete()` with SoftDeletes). Model events fire, so you can clean up files there.
- **`anonymize`** runs your anonymiser once and sets `anonymized_at`. The record is never processed again.
- **Restriction** (Art. 18 GDPR, § 35 BDSG) is a state, not a final action. The `Restrictable` trait adds a global scope, like `SoftDeletes`, that hides restricted records from all normal queries:

```php
$customer->restrict();                  // e.g. while the accuracy of the data is contested
$customer->unrestrict();
Customer::withRestricted()->find($id);
Customer::onlyRestricted()->get();
```

A restricted record still receives its final action when its period expires. `retention:run` ignores all global scopes, because retention applies to every row.

**Dependencies.** If deleting a record would break others, declare it. The record is deleted only once these relations are empty, and `retention:run` handles the children first:

```php
RetentionPolicy::category('orders', 'Orders')
    ->rule('bgb_limitation_period')
    ->startsAt('completed_at', StartEvent::EndOfTransaction)
    ->action('delete')
    ->blockedBy(['invoices'])
    ->responsible('Sales');
```

## Erasure requests

```php
use ItsJustVita\Retention\Erasure;

$report = Erasure::for($customer)
    ->including(['invoices', 'orders.invoices'])  // dot notation for nested relations
    ->execute();                                  // or ->dryRun()

$reply = $report->toMarkdown('de');               // a reply for the data subject
$data = $report->toArray();
```

Children are processed before their parents and the subject last, in one transaction. For each record, the first matching case applies:

| Situation | Result |
|---|---|
| On legal hold, its own or an inherited one | Kept |
| Start column is `null`, e.g. the contract is still running | Left untouched and reported, unless the policy says `->onErasureBeforeStart('apply')` |
| The rule blocks erasure and the period is still running | Restricted until the period expires, with the date in the report |
| `delete`, and `blockedBy()` relations still hold records | Restricted until these are gone |
| Otherwise | Final action right away |

If a related model has no policy, `execute()` fails before changing anything; `dryRun()` lists it.

> [!WARNING]
> Relation names in `including()` must come from your code, never from user input. A method without a return type is tried once on a blank instance to find out whether it is a relation.

## Legal hold

```php
$invoice->placeHold('Tax audit 2026', until: now()->addYear());
$invoice->isOnHold();
$invoice->releaseHold();
```

While a hold is active, the record is never deleted or anonymised, also not by erasure requests. With `->inheritsHoldsFrom('customer')`, a hold on a customer protects their invoices too, even if the customer is restricted or soft-deleted. Inheritance is not transitive; list nested paths explicitly (`'order.customer'`). Holds name the person who placed them, so released and expired holds are deleted after `retention.holds.retention` (default 3 years).

## Audit

`retention:audit` is meant for CI. It exits with a non-zero code on errors, and with `--strict` also on warnings.

- **Models:** every Eloquent model in `retention.models.paths` (default `app/Models`) needs a policy or an exclusion.
- **Tables:** every table needs a model with a policy, a table policy or an exclusion. This catches tables without a model that often hold personal data.
- **Policies:** all declarations must be valid, and the package tables must exist.
- **Foreign keys:** an `ON DELETE CASCADE` into rows that a blocking rule must keep is an error. A foreign key that `blockedBy()` does not cover is a warning.

```php
// config/retention.php
'tables' => [
    'sessions' => [
        'category' => 'sessions',
        'label' => 'User sessions',
        'rule' => 'server_logs',
        'starts_at' => 'last_activity',   // a Unix timestamp column
        'start_event' => 'collection',
        'responsible' => 'IT',
    ],
],

'exclusions' => [
    'models' => [
        App\Models\Country::class => 'Reference data without personal data.',
    ],
    'tables' => [
        'jobs' => 'Queue entries are removed once processed.',
    ],
],
```

Table policies only support deletion, in batches through the query builder: no model events and no legal holds, and the log records only the number of rows. Exclusions need a justification, which ends up in the document.

## Deletion log

Every deletion, anonymisation and restriction is written to `retention_log`, in the same transaction: model, category, rule, action, trigger (`schedule`, `erasure_request`, `manual`), time and a hashed record ID.

```php
use ItsJustVita\Retention\Models\RetentionLog;

RetentionLog::verify(Invoice::class, 4711);   // the entries of a record whose ID you know
```

The hash is an HMAC with `RETENTION_LOG_KEY`, falling back to `APP_KEY` (`retention:audit` warns about that, because rotating `APP_KEY` would make older entries unverifiable). After rotating the log key, list the old one under `retention.log.previous_keys`. Since you hold the key, the entries are pseudonymised, not anonymous, and remain personal data (Recital 26 GDPR). They are deleted after `retention.log.retention` (default 3 years). Disable the log with `RETENTION_LOG_ENABLED=false`.

Events for your own logic: `RecordDeleted`, `RecordAnonymized`, `RecordRestricted`, `TableRowsDeleted`, `ErasureCompleted`, `RunCompleted`.

## Deletion concept

```bash
php artisan retention:generate --format=md|html|pdf --locale=en|de --output=path --as-of=2026-11-07
```

Set the cover data in `retention.document`, and describe backups, external services and mailboxes in `retention.document.systems_not_covered`. PDF needs `composer require barryvdh/laravel-dompdf`; otherwise print the HTML. Change layout and wording with `--tag=retention-views` and `--tag=retention-translations`.

The output is deterministic: the same declarations and the same `--as-of` date give byte-identical output, so you can version the document and read its diffs. Without `--as-of`, the cover shows the date and hash of the last Git commit.

The sections are: cover page, purpose and scope, data categories, deletion class matrix, deletion rules per data category, implementation, exclusions, and systems not covered. An excerpt:

```markdown
## 3. Deletion class matrix

Deletion classes combine the period with the event that starts it.

| Period | Collection of the data | End of the transaction | End of the relationship |
|---|---|---|---|
| 7 days | Failed queue jobs, User sessions |  |  |
| 3 years |  |  | Customer master data* |
| 8 years |  | Customer invoices* |  |

* Counted from the end of the calendar year in which the start event occurred.

## 4. Deletion rules per data category

### Customer invoices

- **Applies to:** App\Models\Invoice (invoices)
- **Rule:** Accounting vouchers (e.g. invoices) (Statutory)
- **Period:** 8 years
- **Start event:** End of the transaction
- **Counting:** From the end of the calendar year of the start event
- **Legal basis:** § 147 (1) no. 4, (3), (4) AO; § 257 (1) no. 4, (4), (5) HGB; § 14b (1) UStG
- **Final action:** Delete
- **On an erasure request:** Restricted until the period expires (Art. 17 (3) (b) GDPR; § 35 (3) BDSG)
- **Responsible unit:** Accounting
```

## Known limitations

- **No tax assessment data.** The package cannot tell whether a tax assessment period is still open (§ 147 (3) AO); use legal holds for that.
- **Only the application's database.** Backups, external services, mailboxes and files are not covered. Files only go away if you remove them in a model event. Deleted data stays in backups until they rotate; after restoring a backup, run `retention:run` again before using the data.
- **Unique indexes.** Restricted rows still count against them, e.g. a returning customer who signs up again with the same email address.
- **Primary keys.** The published `retention_holds` migration uses `morphs()`. Change it to `uuidMorphs()` or `ulidMorphs()` if your models use these keys.
- **Same connection.** Legal holds use subqueries, so `retention_holds` has to be on the same database connection as the models.
- **No tenant-specific periods** and no UI in this version.

## Development

```bash
composer install
composer test      # Pest
composer analyse   # PHPStan / Larastan, level 8
composer format    # Pint
```

CI runs the tests on PHP 8.4 and 8.5 with the lowest and the current dependencies on SQLite, and on MySQL 8.4, MariaDB 11.4 and PostgreSQL 18.

## License

MIT. See [LICENSE.md](LICENSE.md).
