<?php

declare(strict_types=1);

return [
    'title' => 'Result of the erasure request',
    'processed_at' => 'Processed on :date.',
    'dry_run' => 'Preview only: nothing has been changed yet.',
    'records' => ':count record|:count records',
    'date_format' => 'Y-m-d',

    'status' => [
        'deleted' => 'Deleted: :records.',
        'anonymized' => 'Anonymised: :records. The remaining data can no longer be linked to you.',
        'restricted' => 'Restricted: :records. We keep this data until :date (:ground). Until then it is blocked for any other use; afterwards it will be :action.',
        'restricted_legal_duty' => 'Restricted: :records. We are legally required to keep this data until :date (:ground). Until then it is blocked for any other use; afterwards it will be :action.',
        'restricted_legal_claims' => 'Restricted: :records. We keep this data until :date to establish, exercise or defend legal claims (:ground). Until then it is blocked for any other use; afterwards it will be :action.',
        'restricted_dependent' => 'Restricted: :records. This data is linked to records we still keep. It is blocked for any other use and will be :action together with them.',
        'on_hold' => 'Kept: :records, because of a legal hold (for example pending proceedings or a tax audit).',
        'start_event_pending' => 'Not erased: :records. The data is still needed, for example for an active contract.',
        'cancelled' => 'Not deleted: :records. The deletion was stopped by the application; please contact us.',
        'no_policy' => 'Not covered: :records. These records have no retention policy.',
    ],

    'actions' => [
        'delete' => 'deleted',
        'anonymize' => 'anonymised',
    ],
];
