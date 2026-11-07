<?php

declare(strict_types=1);

return [
    'title' => 'Ergebnis des Löschantrags',
    'processed_at' => 'Bearbeitet am :date.',
    'dry_run' => 'Nur Vorschau: Es wurde noch nichts geändert.',
    'records' => ':count Datensatz|:count Datensätze',
    'date_format' => 'd.m.Y',

    'status' => [
        'deleted' => 'Gelöscht: :records.',
        'anonymized' => 'Anonymisiert: :records. Die verbleibenden Daten lassen sich Ihnen nicht mehr zuordnen.',
        'restricted' => 'Eingeschränkt: :records. Wir müssen diese Daten bis zum :date aufbewahren (:ground). Bis dahin sind sie für jede andere Verarbeitung gesperrt; danach werden sie :action.',
        'restricted_dependent' => 'Eingeschränkt: :records. Diese Daten hängen mit Datensätzen zusammen, die wir aufbewahren müssen. Sie sind für jede andere Verarbeitung gesperrt und werden gemeinsam mit diesen :action.',
        'on_hold' => 'Aufbewahrt: :records, wegen einer rechtlichen Aufbewahrungsanordnung (zum Beispiel ein laufendes Verfahren oder eine Betriebsprüfung).',
        'start_event_pending' => 'Nicht gelöscht: :records. Die Daten werden noch benötigt, zum Beispiel für einen laufenden Vertrag.',
        'cancelled' => 'Nicht gelöscht: :records. Die Löschung wurde von der Anwendung verhindert; bitte wenden Sie sich an uns.',
        'no_policy' => 'Nicht erfasst: :records. Für diese Datensätze gibt es keine Aufbewahrungsregel.',
    ],

    'actions' => [
        'delete' => 'gelöscht',
        'anonymize' => 'anonymisiert',
    ],
];
