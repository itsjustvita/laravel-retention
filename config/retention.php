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
