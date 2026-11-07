<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Timezone in which retention periods are calculated. "End of the calendar
    | year" means 31 December, 24:00, in this timezone. Null falls back to
    | the application timezone (config('app.timezone')).
    |
    */

    'timezone' => env('RETENTION_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Where to look for Eloquent models. retention:run and retention:due
    | process every model with the HasRetention trait; retention:audit
    | reports models without a policy. Models outside these paths (e.g.
    | from packages) can be listed under `additional`.
    |
    */

    'models' => [
        'paths' => [app_path('Models')],
        'additional' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunk size
    |--------------------------------------------------------------------------
    |
    | Records per chunk in retention:run. Each chunk runs in its own
    | database transaction.
    |
    */

    'chunk_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Table policies
    |--------------------------------------------------------------------------
    |
    | Retention rules for tables without a model that may hold personal data,
    | e.g. sessions, password_reset_tokens, failed_jobs or notifications.
    | Rows are deleted through the query builder: no model events, no legal
    | holds, and the deletion log records only the number of rows.
    | The start column may be a date, datetime or Unix timestamp column.
    |
    | 'sessions' => [
    |     'category' => 'sessions',
    |     'label' => 'User sessions',
    |     'rule' => 'server_logs',
    |     'starts_at' => 'last_activity',
    |     'start_event' => 'collection',   // collection | end_of_transaction | end_of_relationship
    |     'responsible' => 'IT',
    | ],
    |
    */

    'tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Exclusions
    |--------------------------------------------------------------------------
    |
    | Models and tables deliberately without a retention policy. Every entry
    | needs a justification, which ends up in the deletion concept. An
    | excluded model also covers its table. Only tables without personal
    | data are excluded by default; decide for yourself about sessions,
    | jobs, failed_jobs, password_reset_tokens and notifications.
    |
    */

    'exclusions' => [
        'models' => [],
        'tables' => [
            'migrations' => 'Framework bookkeeping without personal data.',
            'cache' => 'Short-lived framework cache. Review this if personal data is cached.',
            'cache_locks' => 'Framework locks without personal data.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    |
    | The database connections whose tables retention:audit checks. Null is
    | the default connection.
    |
    */

    'audit' => [
        'connections' => [null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal holds
    |--------------------------------------------------------------------------
    |
    | Holds name the person who placed them, so released and expired holds
    | are deleted by retention:run once this period (ISO 8601) has passed.
    |
    */

    'holds' => [
        'retention' => 'P3Y',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deletion log
    |--------------------------------------------------------------------------
    |
    | Every deletion, anonymisation and restriction is logged as evidence
    | under Art. 5 (2) GDPR. Record IDs are stored only as an HMAC, keyed
    | with RETENTION_LOG_KEY (falls back to APP_KEY, which then must never
    | change). After rotating the key, list the old one under
    | previous_keys so older entries stay verifiable. Entries are deleted
    | by retention:run once `retention` (ISO 8601) has passed.
    |
    */

    'log' => [
        'enabled' => env('RETENTION_LOG_ENABLED', true),
        'key' => env('RETENTION_LOG_KEY'),
        'previous_keys' => [],
        'retention' => 'P3Y',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rule catalogue
    |--------------------------------------------------------------------------
    |
    | The package ships a catalogue of German retention rules (see
    | ItsJustVita\Retention\Rules\ShippedRules). Entries here override a
    | shipped rule key by key, or add a custom rule under a new key.
    | `null` removes an optional key. The catalogue is not legal advice
    | and must be reviewed by the controller.
    |
    | 'rules' => [
    |     // Supervised institution with a later BEG IV transition date:
    |     'ao_accounting_voucher' => ['valid_from' => '2026-01-01'],
    |
    |     // Custom rule:
    |     'newsletter_tracking' => [
    |         'label' => 'Newsletter tracking data',
    |         'period' => 'P1Y',              // ISO 8601: P8Y, P6M, P7D, PT72H
    |         'counting' => 'event',          // event | end_of_year
    |         'legal_basis' => 'Art. 6 (1) (a) GDPR',
    |         'type' => 'custom',             // legal | recommendation | custom
    |         'blocks_erasure' => false,
    |     ],
    | ],
    |
    */

    'rules' => [],

];
