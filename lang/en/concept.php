<?php

declare(strict_types=1);

return [
    'title' => 'Deletion concept',
    'subtitle' => 'Structured according to DIN 66398',
    'date_format' => 'Y-m-d',
    'not_set' => 'not set',

    'cover' => [
        'organisation' => 'Organisation',
        'controller' => 'Controller',
        'dpo' => 'Data protection officer',
        'version' => 'Version',
        'as_of' => 'As of',
        'commit' => 'Commit',
    ],

    'purpose' => [
        'heading' => '1. Purpose and scope',
        'text' => 'This deletion concept documents which personal data this application stores, how long it is kept, and how it is deleted, anonymised or restricted. It is generated from the same declarations the application enforces, so the document and the actual behaviour match. It covers the data in the application\'s database. It supports accountability under Art. 5 (2) GDPR and implements storage limitation under Art. 5 (1) (e) GDPR.',
    ],

    'categories' => [
        'heading' => '2. Data categories',
        'category' => 'Data category',
        'targets' => 'Models and tables',
        'deletion_class' => 'Deletion class',
        'rule' => 'Rule',
        'legal_basis' => 'Legal basis',
        'action' => 'Final action',
        'erasure' => 'On an erasure request',
        'responsible' => 'Responsible unit',
        'blocking' => 'Restricted until the period expires (:ground)',
        'not_blocking' => 'Final action right away',
    ],

    'matrix' => [
        'heading' => '3. Deletion class matrix',
        'intro' => 'Deletion classes combine the period with the event that starts it.',
        'period' => 'Period',
        'end_of_year' => '* Counted from the end of the calendar year in which the start event occurred.',
    ],

    'details' => [
        'heading' => '4. Deletion rules per data category',
        'description' => 'Description',
        'targets' => 'Applies to',
        'rule' => 'Rule',
        'type' => 'Type of rule',
        'period' => 'Period',
        'start_event' => 'Start event',
        'counting' => 'Counting',
        'legal_basis' => 'Legal basis',
        'action' => 'Final action',
        'erasure' => 'On an erasure request',
        'responsible' => 'Responsible unit',
        'note' => 'Note',
        'previous' => 'Earlier versions',
        'previous_version' => 'Until :date: :period',
        'blocked_by' => 'Deleted only once these relations are empty: :relations',
        'inherits_holds' => 'Legal holds inherited from: :relations',
    ],

    'implementation' => [
        'heading' => '5. Implementation',
        'deletion_heading' => 'Deletion',
        'deletion' => 'Records are deleted permanently once their period has expired, including records that were only soft-deleted before. A record is only deleted once the records that depend on it are gone. Rows of tables without a model are deleted in batches.',
        'anonymisation_heading' => 'Anonymisation',
        'anonymisation' => 'Records with the final action "anonymise" keep their row, but their personal data is removed once. Afterwards they are not processed again.',
        'restriction_heading' => 'Restriction of processing',
        'restriction' => 'Restricted records (Art. 18 GDPR, § 35 BDSG) are hidden from the normal processing of the application. They receive their final action when their period expires.',
        'erasure_heading' => 'Erasure requests',
        'erasure' => 'An erasure request (Art. 17 GDPR) is decided per record: records on legal hold are kept; records whose period has not started yet (e.g. an active contract) are left untouched and reported; records whose rule justifies keeping them are restricted until the period expires; records with dependent records are restricted until these are gone; all others receive their final action right away. The result can be used to reply to the data subject.',
        'schedule_heading' => 'Execution',
        'schedule' => 'Due records are processed by the command retention:run, which the application runs on a regular schedule. Periods are calculated in the timezone :timezone. Before every run, all declarations are validated; if any of them is invalid, nothing is changed. The command retention:audit reports models and tables without a deletion rule.',
        'log_heading' => 'Deletion log',
        'log' => 'Every deletion, anonymisation and restriction is logged as evidence (Art. 5 (2) GDPR): data category, rule, action, trigger, time and a hashed record ID. The hash is keyed, so a specific record can be verified on demand, but the ID cannot be read from the log. Because the controller holds the key, the entries are pseudonymised and remain personal data (Recital 26 GDPR). They are kept for :period and then deleted. Rows of tables without a model are logged only as a number of rows.',
        'log_disabled' => 'The deletion log is disabled.',
        'hold_heading' => 'Legal hold',
        'hold' => 'While a legal hold is active (e.g. pending litigation or a tax audit), the record is neither deleted nor anonymised. A hold can also protect the records that depend on the held record. Holds name the person who placed them; released and expired holds are deleted after :period.',
    ],

    'exclusions' => [
        'heading' => '6. Exclusions',
        'intro' => 'These models and tables deliberately have no retention rule.',
        'none' => 'There are no exclusions.',
        'name' => 'Model or table',
        'justification' => 'Justification',
    ],

    'systems' => [
        'heading' => '7. Systems not covered',
        'text' => 'The application only sees its own database. Other systems have to be covered separately, in particular external services, mailboxes and file storage (files are only removed if the application does so when a record is deleted). Backups: deleted data remains in backups until they are rotated. If a backup is restored, the deletion run has to be repeated before the data is used again. Describe the rotation periods here.',
    ],

    'units' => [
        'year' => ':count year|:count years',
        'month' => ':count month|:count months',
        'day' => ':count day|:count days',
        'hour' => ':count hour|:count hours',
        'minute' => ':count minute|:count minutes',
        'second' => ':count second|:count seconds',
    ],

    'start_events' => [
        'collection' => 'Collection of the data',
        'end_of_transaction' => 'End of the transaction',
        'end_of_relationship' => 'End of the relationship',
    ],

    'counting' => [
        'end_of_year' => 'From the end of the calendar year of the start event',
        'event' => 'From the start event',
    ],

    'actions' => [
        'delete' => 'Delete',
        'anonymize' => 'Anonymise',
    ],

    'types' => [
        'legal' => 'Statutory',
        'recommendation' => 'Recommendation',
        'custom' => 'Defined by the controller',
    ],
];
