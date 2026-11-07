# laravel-retention – Specification

Working package name: `itsjustvita/laravel-retention`. Namespace `ItsJustVita\Retention`. All code, docs, config labels and comments are in English. German terms appear only where they are legal terms of art (e.g. "Löschkonzept", statute names) and are always explained on first use. The generated document is localisable (en and de translation files shipped).

> **Revision 2 (2026-11-07).** Revised after review; see "Changes in revision 2" at the end. The design decisions D1–D3 taken in this revision are summarised under "Decisions".

## Goal

Laravel applications declare their retention and deletion rules directly in code. The package enforces these rules automatically (delete or anonymise, with restriction while erasure is blocked) and generates a deletion concept document ("Löschkonzept", structured according to DIN 66398) from the very same declarations. The document and the actual behaviour can therefore never drift apart. This is the core of the package and everything else serves it.

How it differs from existing retention packages: those delete after generic time periods. This package knows German statutory retention periods (AO, HGB, BGB limitation periods), the rule that periods start at the end of the calendar year, restriction instead of deletion under Art. 18 GDPR / § 35 BDSG while a retention duty is still running, and it produces the document that data protection officers ask for.

## Constraints

PHP 8.4+, Laravel 13. Tests with Pest and Orchestra Testbench, time control via `Carbon::setTestNow()` or `$this->travelTo()`. Static analysis with PHPStan/Larastan level 8, code style with Pint. No dependencies beyond Laravel itself; PDF export optional via a suggested package.

## Terminology

**Data category** (DIN 66398 "Datenart"): a business-level group of personal data, e.g. "customer master data", "invoices", "job applications", "server logs". Each model or table is assigned exactly one data category; a category may span several models.

**Retention rule**: period, period counting, legal basis and erasure behaviour, e.g. "8 years from the end of the calendar year in which the voucher was created, § 147 (1) no. 4, (3) AO; blocks erasure under Art. 17 (3) (b) GDPR".

**Start event** (DIN 66398 "Startzeitpunkt"): the business event that starts the period: `collection` (the data was collected), `end_of_transaction` (the business transaction is complete, e.g. invoice issued) or `end_of_relationship` (e.g. contract ended). Declared per policy, together with the column that holds its date.

**Period counting**: whether the period runs from the start event itself (`event`) or from the end of the calendar year in which the start event occurred (`end_of_year`). A property of the rule, independent of the start event.

**Deletion class** (DIN 66398 "Löschklasse"): combination of start event and period. Derived from the policies and rendered as a matrix in the document.

**Blocking rule**: a rule with `blocks_erasure: true`. While its period is running, an erasure request leads to restriction instead of erasure (Art. 17 (3) (b) or (e) GDPR, § 35 (3) BDSG).

**Final action**: what happens when the period expires: `delete` or `anonymize`.

**Restriction** (Art. 18 GDPR, § 35 BDSG): not a final action but a state. A record is restricted when erasure is requested while a blocking rule is still running, or manually. It still receives its final action when the period expires.

## Rule catalogue

Shipped catalogue in `config/retention.php` (publishable). Each rule is a keyed entry that can be overridden or extended. Structure:

```php
'rules' => [
    'ao_accounting_voucher' => [
        'label' => 'Accounting vouchers (e.g. invoices)',
        'period' => 'P8Y',
        'counting' => 'end_of_year',        // end_of_year | event
        'legal_basis' => '§ 147 (1) no. 4, (3) AO; § 257 (1) no. 4, (4) HGB; § 14b (1) UStG',
        'type' => 'legal',                  // legal | recommendation | custom
        'blocks_erasure' => true,
        'erasure_ground' => 'Art. 17 (3) (b) GDPR, § 35 (3) BDSG',
        'valid_from' => '2025-01-01',
        'previous' => ['period' => 'P10Y'],
        'note' => 'Reduced from 10 to 8 years by the Fourth Bureaucracy Relief Act (Bürokratieentlastungsgesetz IV, BEG IV).',
    ],
    // ...
],
```

`blocks_erasure` is set explicitly on every rule and is deliberately not derived from `type`. The BGB limitation period is not a retention duty but still justifies keeping data for the defence of legal claims (Art. 17 (3) (e) GDPR), whereas a recommendation such as the server log period does not justify refusing erasure. `erasure_ground` is rendered in the document and in the erasure report.

Rules to ship (verify contents against current law before release; every rule carries `legal_basis` and an as-of date):

| Key | Period | Counting | Blocks erasure | Basis |
|---|---|---|---|---|
| `ao_accounting_voucher` | 8 years | end of year | yes, Art. 17 (3) (b) | § 147 AO, § 257 HGB, § 14b UStG (since BEG IV) |
| `ao_books_financial_statements` | 10 years | end of year | yes, Art. 17 (3) (b) | § 147 (1) no. 1, (3) AO; § 257 HGB |
| `ao_business_correspondence` | 6 years | end of year | yes, Art. 17 (3) (b) | § 147 (1) nos. 2, 3, 5 AO; § 257 HGB |
| `bgb_limitation_period` | 3 years | end of year | yes, Art. 17 (3) (e) | §§ 195, 199 BGB (contract data after contract end) |
| `rejected_application` | 6 months | event | yes, Art. 17 (3) (e) | Recommendation based on § 15 (4) AGG, § 61b ArbGG, `type: recommendation` |
| `server_logs` | 7 days | event | no | Recommendation, `type: recommendation` |
| `consent_proof` | 3 years | end of year | yes, Art. 17 (3) (e) | Proof of consent, Art. 7 (1) GDPR, `type: recommendation` |

The README must state clearly that the catalogue is not legal advice and has to be reviewed by the controller.

### Rule changes over time (`valid_from`, `previous`)

Transitional rules are not hard-coded (e.g. BEG IV applies to records whose period had not expired by 2025-01-01; certain supervised institutions have different transition dates). Instead every rule has `valid_from` and an optional `previous` (any subset of rule keys, typically `period`) so users can configure transitions themselves.

The rule version is chosen by the **evaluation date**, not by the date of the start event. For evaluation date N and a record with start event E:

- N before `valid_from`: `previous` applies.
- N on or after `valid_from`: if the record had already expired under `previous` before `valid_from`, it stays expired. Otherwise the current version applies.

This matches BEG IV, which applies to all records whose period had not yet expired on 2025-01-01. On that day, vouchers from 2015 and 2016 became deletable at once. Supervised institutions with a later transition date configure their own `valid_from`.

> **Decision D2:** evaluation date, as above. The alternative, event date (the new version applies only to records whose start event is on or after `valid_from`), is simpler but gets BEG IV wrong: it would keep vouchers from 2015–2024 for 10 years. A per-rule switch `transition: evaluation_date|event_date` is deferred until a concrete law needs event-date semantics.

### Period calculation

With `end_of_year`, the period starts at the end of the calendar year in which the start event occurred (§ 147 (4) AO, § 257 (5) HGB, § 199 (1) BGB). Example: invoice dated 2025-03-15, 8 years, period starts 2025-12-31, deletable from 2034-01-01.

All calculations run in `config('retention.timezone')` (default: `app.timezone`). "End of the calendar year" means 31 December, 24:00, in that timezone. Datetime columns are converted from the database timezone first, date columns are taken as calendar dates, integer columns are treated as Unix timestamps (e.g. `sessions.last_activity`).

The calculation lives in its own thoroughly tested class `RetentionCalculator`, which works in both directions:

- `expiresAt(event, rule, now)`: the instant from which a record is deletable.
- `dueConstraint(rule, now)`: the inverse, a constraint on the start column (`start_column < cutoff`, or two cutoffs combined with OR during a transition). `retention:run` and `retention:due` use it to select due records in SQL instead of evaluating every row in PHP. `retention:due` calls it with `now + 30 days` for the forecast column.

Both directions must agree: for every E and N, `expiresAt(E) <= N` holds exactly when E satisfies `dueConstraint(N)`. This equivalence is tested over generated dates.

## Declaring policies on models

```php
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\RetentionPolicy;

class Invoice extends Model
{
    use HasRetention;

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('invoices', 'Customer invoices')
            ->rule('ao_accounting_voucher')
            ->startsAt('issued_at', StartEvent::EndOfTransaction)
            ->action('delete')
            ->inheritsHoldsFrom('customer')  // see Legal hold
            ->responsible('Accounting');
    }
}

class Customer extends Model
{
    use HasRetention;

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('customer_master_data', 'Customer master data')
            ->rule('bgb_limitation_period')
            ->startsAt('contract_ended_at', StartEvent::EndOfRelationship) // null = period not running yet
            ->action('anonymize')
            ->anonymizeUsing(fn (Customer $c) => $c->forceFill([
                'name' => 'deleted', 'email' => null, 'phone' => null,
            ])->saveQuietly())
            ->responsible('Sales');
    }
}
```

`action()` is required and has no default, because the document must show an explicit decision for every data category.

If the start column is `null`, no period is running (e.g. contract still active).

As an alternative to the method, configuration via a PHP attribute `#[Retention(...)]` is supported and maps internally to the same `RetentionPolicy` object. The method is the primary API, the attribute is convenience. Attributes cannot contain closures before PHP 8.5, so the attribute names the anonymiser as an invokable class (`anonymizer: CustomerAnonymizer::class`) or a method on the model (`anonymizer: 'anonymizeForRetention'`). `anonymizeUsing()` accepts both forms as well.

### Policy validation

`RetentionPolicy::validate()` checks:

- the rule key exists;
- the start column exists on the table;
- an anonymiser is set for `anonymize`;
- the required columns exist (`anonymized_at` for `anonymize`, `restricted_at` for blocking rules);
- relations named in `blockedBy()` and `inheritsHoldsFrom()` exist, and `blockedBy()` has no cycles.

Validation runs at three points:

- in `retention:audit` (CI);
- before `retention:run`, `retention:due` and `retention:generate` change or output anything (fail fast, no partial run);
- in the app's own tests through the shipped Pest expectation `expect(Invoice::class)->toHaveValidRetentionPolicy()`.

Column checks need a migrated database.

## Actions

### Final actions

`delete`: hard delete. With SoftDeletes, `forceDelete()`; soft-deleted records are always included when selecting due records. Model events fire so applications can clean up files etc.

`anonymize`: invokes the anonymiser, then sets `anonymized_at` (migration helper `$table->anonymizable()`). Records with `anonymized_at` set are never selected again, so anonymisation runs exactly once and is logged once. Anonymisation also clears `restricted_at`, since the record no longer contains personal data.

### Restriction

Sets `restricted_at` (migration helper `$table->restrictable()`). A global scope hides restricted records from normal queries. Access only explicitly via `Model::withRestricted()` or `onlyRestricted()`. This implements restriction of processing under Art. 18 GDPR / § 35 BDSG.

A record is restricted in two ways:

- automatically, by an erasure request while a blocking rule is running (see Erasure requests);
- manually via `$model->restrict()` and `->unrestrict()`, e.g. while the accuracy of the data is contested (Art. 18 (1) (a) GDPR).

A restricted record still receives its final action when its period expires. `retention:run` and `Erasure` always query with `withRestricted()`.

Known limitation (README): restricted rows still count against unique indexes, e.g. a returning customer who signs up again with the same email address.

> **Decision D3:** the first draft had `restrict` and `restrict_then_delete` as actions. Final actions are now only `delete` and `anonymize`; restriction is a state, not an action. This removes the unclear case "restrict at expiry and keep forever", which contradicts storage limitation (Art. 5 (1) (e) GDPR). It also lets anonymisation combine with restriction (customer restricted on erasure request, anonymised at expiry), which `restrict_then_delete` could not express. Data that must be kept without a fixed end (rare) is declared as an exclusion with a justification instead.

## Dependencies between models

Deleting a record can destroy data that must still be kept (an `ON DELETE CASCADE` foreign key on a retained child table) or fail on a foreign key constraint. Therefore:

A policy declares the relations that block its final action:

```php
RetentionPolicy::category('orders', 'Orders')
    ->rule('bgb_limitation_period')
    ->startsAt('completed_at', StartEvent::EndOfTransaction)
    ->action('delete')
    ->blockedBy(['invoices']);
```

The record is only deleted once these relations hold no records, including restricted and soft-deleted ones. `blockedBy()` is only needed for `delete`; `anonymize` keeps the row and its keys.

`retention:run` processes models in topological order of `blockedBy()`, so children are handled before their parents in the same run. Cycles are a validation error.

`retention:audit` reads the foreign keys of all covered tables (`Schema::getForeignKeys()`):

- **Error:** a table governed by a blocking rule has an `ON DELETE CASCADE` foreign key to a table whose policy is `delete`. Deleting the parent would bypass the child's retention duty.
- **Warning:** a foreign key references a table whose policy is `delete`, but that policy's `blockedBy()` does not cover the referencing table. Deletion would fail on the constraint or orphan rows.

## Erasure requests (Art. 17 GDPR)

```php
$report = Erasure::for($customer)
    ->including(['invoices', 'tickets', 'contactRequests.attachments']) // relations, dot notation for nested ones
    ->execute();                                                        // or ->dryRun()
```

The subject (`$customer`) is always included. Relations are loaded with restricted and soft-deleted records. Records are processed depth-first and the subject last, so that `blockedBy()` sees the final state of the children. If a related model has no policy, `execute()` throws `MissingRetentionPolicy` before anything is changed; `dryRun()` lists it in the report.

Decision per record; the first matching row applies:

| # | Situation | Result | Report status |
|---|---|---|---|
| 1 | Record is on hold (own or inherited) | nothing | `on_hold` |
| 2 | Start column is null | see D1 | `start_event_pending` |
| 3 | Blocking rule, period still running | restrict | `restricted`, with deletion date and `erasure_ground` |
| 4 | Final action `delete`, but `blockedBy()` relations still hold records | restrict | `restricted`, deletion date pending |
| 5 | Otherwise (period expired, or non-blocking rule) | final action now | `deleted` / `anonymized` |

> **Decision D1:** when the start column is null (e.g. contract still active), the record is left untouched and reported as `start_event_pending` with an explanation ("still required, e.g. for an active contract"; Art. 17 (1) (a) GDPR does not apply while the data is still needed for its purpose). The controller then decides by hand. A policy can opt into `->onErasureBeforeStart('apply')` where a missing start event means "no duty yet", e.g. draft invoices without `issued_at`. Rejected alternatives: restrict immediately (dangerous: the global scope would hide an active customer from the application) or apply the final action immediately (wrong for active contracts).

The returned `ErasureReport` lists per data category what was deleted, anonymised, restricted (with the final deletion date and erasure ground), held, or left pending. It should be directly usable for replying to the data subject (`toArray()`, `toMarkdown()`, localised en/de). The deletion date shown for restricted records is computed with the rule in force when the report is created; if the rule changes later, the actual deletion follows the new rule.

## Legal hold

Polymorphic table `retention_holds` (holdable_type, holdable_id, reason, until nullable, created_by nullable, released_at nullable, timestamps). A hold is active while `released_at` is null and `until` is null or in the future; it expires by itself at `until`. While an active hold exists, the record is never deleted or anonymised. Use cases are pending litigation, tax audits, or the suspension of expiry under § 147 (3) sentence 5 AO.

API: `$invoice->placeHold('Tax audit 2026', until: ...)`, `->releaseHold()`, `->isOnHold()`.

A policy can inherit holds through a relation: with `->inheritsHoldsFrom('customer')`, a hold on a customer also protects their invoices without one hold per invoice. `isOnHold()` and the due-record query both take inherited holds into account (`whereDoesntHave` on the relation's active holds).

Holds contain personal data of employees (`created_by`, possibly `reason`). Released and expired holds are purged after `holds_retention` (default 3 years, configurable) by `retention:run`.

## Execution

Artisan commands:

`retention:run` validates all policies, then executes all due final actions. Due records are selected in SQL via `dueConstraint()`; records on hold, already anonymised, or blocked by `blockedBy()` are excluded; restricted and soft-deleted records are included. Processes in chunks (`lazyById`), one transaction per chunk, models in dependency order, with `--model=` to narrow scope and `--dry-run`. Meant for the scheduler; the package does not register itself, the README shows the line for `routes/console.php`.

`retention:due` prints a table: data category, model or table, count due now, count due within 30 days, count restricted, count on hold.

`retention:audit` checks coverage on three levels and exits with a non-zero code on any error, so it can run in CI. This is a central feature because it stops new tables from shipping without a deletion rule.

1. **Models:** scans the configured paths (default `app/Models`) and lists every Eloquent model without a policy and without an exclusion.
2. **Tables:** lists every table of the configured connections (`Schema::getTables()`) that is neither used by a model with a policy, nor governed by a table policy, nor excluded. This catches tables without models that often hold personal data: `sessions`, `password_reset_tokens`, `failed_jobs`, `jobs`, `notifications`, `telescope_entries`.
3. **Policies:** runs validation and the foreign key checks (see Dependencies). Warns if the deletion log falls back to `APP_KEY`.

Exclusions for models and tables are configured with a justification that ends up in the document. Default exclusions are shipped only for tables without personal data (`migrations`, `cache`, `cache_locks`). The package's own tables are covered by their own rules.

Table policies in the config cover tables without a model. They support only `delete` and run through the query builder, so no model events fire:

```php
'tables' => [
    'sessions' => [
        'category' => 'sessions',
        'label' => 'User sessions',
        'rule' => 'server_logs',
        'starts_at' => 'last_activity',  // Unix timestamp column
        'start_event' => 'collection',
        'responsible' => 'IT',
    ],
],
```

`retention:generate` produces the document (see below).

Every action dispatches events (`RecordDeleted`, `RecordAnonymized`, `RecordRestricted`, `ErasureCompleted`, `RunCompleted`) so applications can hook in their own logic.

## Deletion log

Table `retention_log` as evidence under the accountability principle, Art. 5 (2) GDPR. It stores only minimal, pseudonymised data: model class or table, hashed ID, key ID, data category, rule, action, trigger (`schedule`, `erasure_request`, `manual`), timestamp.

The hashed ID is an HMAC with a dedicated key (`RETENTION_LOG_KEY`, falling back to `APP_KEY`). A separate key means rotating `APP_KEY` does not break verification; `key_id` records which key produced each hash, so the log key itself can be rotated. `RetentionLog::verify(Invoice::class, 4711)` finds the entries for a specific record when its ID is known from elsewhere (e.g. the data subject's customer number), but the hash cannot be reversed.

Because the controller holds the key, the hashed IDs are pseudonymised, not anonymous, and remain personal data (Recital 26 GDPR). The document says so. The log therefore has its own retention rule (default 3 years, configurable). Can be disabled, enabled by default.

## Generated deletion concept

`php artisan retention:generate --format=md|html [--locale=en|de] [--output=path] [--as-of=YYYY-MM-DD]`. PDF only if an optional package is installed; otherwise a hint to print the HTML.

Structure following DIN 66398:

1. Cover page with organisation, controller, data protection officer (from config), version and as-of date. The as-of date is the date of the last Git commit (shown with its hash) if available, otherwise the generation date; `--as-of` overrides it.
2. Purpose and scope (boilerplate, overridable in config).
3. Data categories: table with category, description, affected models/tables, deletion class, rule, legal basis, final action, erasure behaviour (blocking yes/no with ground), responsible unit.
4. Deletion class matrix: rows are periods (ascending), columns are the start events (collection, end of transaction, end of relationship). Cells list the data categories; categories with end-of-year counting are marked.
5. Detailed deletion rules per data category, including dependencies (`blockedBy`) and inherited holds.
6. Implementation: how deletion, anonymisation, restriction and erasure requests work, execution schedule, deletion log (including its pseudonymisation and retention), legal hold.
7. Exclusions: models and tables deliberately without policy, with justification.
8. Systems not covered: free-text section from config (backups, external services, mailboxes), since the package only sees the app's database. A default note on backups is prefilled.

Templates are publishable Blade views so layout and wording can be customised. The output must be deterministic: the same declarations and the same as-of date produce byte-identical output, and all lists are sorted by stable keys. This way the document can be meaningfully versioned and diffed.

## Data model (migrations)

- `retention_holds` (see Legal hold).
- `retention_log` (see Deletion log).
- Blueprint macro `restrictable()`: adds `restricted_at` (timestamp, nullable, indexed). Required on tables whose policy uses a blocking rule.
- Blueprint macro `anonymizable()`: adds `anonymized_at` (timestamp, nullable, indexed). Required on tables whose policy uses `anonymize`.

The deletion date of a restricted record is not stored. It is always computed from the start column and the rule currently in force, so a rule change such as BEG IV cannot leave stale dates behind.

## Tests

High coverage is mandatory for:

- `RetentionCalculator`: end of year, month periods, leap years, 31 December, timezone boundaries around midnight on 31 December, transitions via `valid_from`/`previous`, and the equivalence of `expiresAt()` and `dueConstraint()` over generated dates.
- Erasure decision logic: every row of the decision table, including own and inherited holds, null start column and `blockedBy()`.
- The global scope for restricted records, and that `run` and `Erasure` see restricted and soft-deleted rows.
- Anonymisation runs once: a second run is a no-op and writes no log entry.
- Dependencies: processing order, `blockedBy()`, cycle detection.
- `audit`: models, tables, table policies, foreign key checks, exit codes.
- The generator: snapshot tests against a fixture set of models.

Plus one feature test that runs a full lifecycle with time travel: customer with invoices, contract end, erasure request, restriction of customer and invoices, anonymisation of the customer after the limitation period, deletion of the invoices after the retention period, log entries.

## Implementation order

1. Package skeleton including Testbench, Pint, PHPStan and CI (GitHub Actions, matrix over PHP 8.4 and 8.5 on Laravel 13).
2. Rule catalogue and `RetentionCalculator` (both directions, transitions) with tests.
3. `HasRetention`, `RetentionPolicy`, the attribute, validation and the Pest expectation.
4. Final actions, the restriction scope, `anonymized_at`, dependencies.
5. `retention:run` and `retention:due`.
6. Legal hold (including inherited holds) and the deletion log.
7. Erasure requests.
8. `retention:audit` (models, tables, table policies, foreign keys).
9. The generator.
10. English README with quickstart, sample output of the generated document, a prominent liability disclaimer and known limitations (unique indexes, backups, external systems).

## Out of scope (v1)

Deletion in external systems (S3 files only via the app's model events), backups, tenant-specific periods and any UI. A Filament integration would be a later, separate package.

## Decisions

| | Question | Decision | Section |
|---|---|---|---|
| D1 | Erasure request while the start column is null | Leave untouched, report as `start_event_pending`; opt-in `onErasureBeforeStart('apply')` | Erasure requests |
| D2 | `valid_from`: evaluation date or event date | Evaluation date (matches BEG IV) | Rule changes over time |
| D3 | Keep `restrict` / `restrict_then_delete` as actions | No: final actions are `delete` and `anonymize`, restriction is a state | Actions |

## Changes in revision 2

- **PHP 8.4+ and Laravel 13 only** (previously PHP 8.3+, Laravel 11–13).
- **Decisions D1–D3** taken and recorded (see Decisions).
- **Start event** (`StartEvent` enum) declared per policy in `startsAt()`; the rule key `starts` is renamed to `counting` (`end_of_year | event`). Without this, the deletion class matrix could not be derived. The matrix columns are now the three DIN start events; end-of-year counting is shown as a marker.
- **`blocks_erasure` and `erasure_ground`** on every rule; the erasure decision is an explicit table.
- **Final actions reduced to `delete` and `anonymize`**; restriction is a state (D3). `action()` is required.
- **`anonymized_at`** (`anonymizable()` macro) so anonymisation is not repeated on every run.
- **Dependencies:** `blockedBy()`, topological processing order, foreign key checks in `audit` (cascade deletes could destroy retained data).
- **`audit` also covers tables** via schema introspection, plus table policies for tables without a model.
- **`RetentionCalculator::dueConstraint()`** as the inverse of `expiresAt()`, so due records are selected in SQL; timezone handling specified.
- **`valid_from` semantics** defined (D2).
- **`retention_delete_after` dropped**; deletion dates are always computed.
- **Legal hold:** `released_at`, automatic expiry at `until`, inherited holds, purging of old holds.
- **Deletion log:** described as pseudonymised (still personal data), dedicated `RETENTION_LOG_KEY` with `key_id`.
- **Erasure:** subject always included, dot notation, processing order, `MissingRetentionPolicy`.
- **Attribute:** anonymiser as invokable class or method name (no closures in attributes).
- **Validation:** when and what is checked; Pest expectation.
- **Execution:** one transaction per chunk instead of per model; soft-deleted records included.
- **Generator:** as-of date from the last Git commit or `--as-of`, byte-identical output.
- **Rule catalogue:** § 14b (1) UStG added to accounting vouchers, § 61b ArbGG to rejected applications.
