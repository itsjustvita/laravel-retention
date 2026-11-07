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
    | Keyed retention rules (period, period counting, legal basis, erasure
    | behaviour). The shipped rules can be overridden or extended here.
    | This catalogue is not legal advice and must be reviewed by the
    | controller.
    |
    */

    'rules' => [],

];
