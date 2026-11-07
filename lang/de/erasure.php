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
        'restricted' => 'Eingeschränkt: :records. Wir bewahren diese Daten bis zum :date auf (:ground). Bis dahin sind sie für jede andere Verarbeitung gesperrt; danach werden sie :action.',
        'restricted_legal_duty' => 'Eingeschränkt: :records. Wir sind gesetzlich verpflichtet, diese Daten bis zum :date aufzubewahren (:ground). Bis dahin sind sie für jede andere Verarbeitung gesperrt; danach werden sie :action.',
        'restricted_legal_claims' => 'Eingeschränkt: :records. Wir bewahren diese Daten bis zum :date auf, um Rechtsansprüche geltend zu machen, auszuüben oder zu verteidigen (:ground). Bis dahin sind sie für jede andere Verarbeitung gesperrt; danach werden sie :action.',
        'restricted_dependent' => 'Eingeschränkt: :records. Diese Daten hängen mit Datensätzen zusammen, die wir noch aufbewahren. Sie sind für jede andere Verarbeitung gesperrt und werden gemeinsam mit diesen :action.',
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
