<?php

declare(strict_types=1);

return [
    'title' => 'Löschkonzept',
    'subtitle' => 'Aufgebaut nach DIN 66398',
    'date_format' => 'd.m.Y',
    'not_set' => 'nicht angegeben',

    'cover' => [
        'organisation' => 'Organisation',
        'controller' => 'Verantwortlicher',
        'dpo' => 'Datenschutzbeauftragte Person',
        'version' => 'Version',
        'as_of' => 'Stand',
        'commit' => 'Commit',
    ],

    'purpose' => [
        'heading' => '1. Zweck und Geltungsbereich',
        'text' => 'Dieses Löschkonzept dokumentiert, welche personenbezogenen Daten diese Anwendung speichert, wie lange sie aufbewahrt werden und wie sie gelöscht, anonymisiert oder eingeschränkt werden. Es wird aus denselben Festlegungen erzeugt, die die Anwendung durchsetzt, sodass Dokument und tatsächliches Verhalten übereinstimmen. Es umfasst die Daten in der Datenbank der Anwendung. Es dient der Rechenschaftspflicht nach Art. 5 Abs. 2 DSGVO und setzt die Speicherbegrenzung nach Art. 5 Abs. 1 lit. e DSGVO um.',
    ],

    'categories' => [
        'heading' => '2. Datenarten',
        'category' => 'Datenart',
        'targets' => 'Models und Tabellen',
        'deletion_class' => 'Löschklasse',
        'rule' => 'Regel',
        'legal_basis' => 'Rechtsgrundlage',
        'action' => 'Endaktion',
        'erasure' => 'Bei einem Löschantrag',
        'responsible' => 'Verantwortliche Stelle',
        'blocking' => 'Einschränkung bis Fristablauf (:ground)',
        'not_blocking' => 'Endaktion sofort',
    ],

    'matrix' => [
        'heading' => '3. Löschklassen-Matrix',
        'intro' => 'Löschklassen verbinden die Frist mit dem Ereignis, das sie auslöst.',
        'period' => 'Frist',
        'end_of_year' => '* Fristbeginn am Ende des Kalenderjahres, in dem das Startereignis eingetreten ist.',
    ],

    'details' => [
        'heading' => '4. Löschregeln je Datenart',
        'description' => 'Beschreibung',
        'targets' => 'Gilt für',
        'rule' => 'Regel',
        'type' => 'Art der Regel',
        'period' => 'Frist',
        'start_event' => 'Startereignis',
        'counting' => 'Fristbeginn',
        'legal_basis' => 'Rechtsgrundlage',
        'action' => 'Endaktion',
        'erasure' => 'Bei einem Löschantrag',
        'responsible' => 'Verantwortliche Stelle',
        'note' => 'Hinweis',
        'previous' => 'Frühere Fassungen',
        'previous_version' => 'Bis :date: :period',
        'blocked_by' => 'Löschung erst, wenn diese Relationen leer sind: :relations',
        'inherits_holds' => 'Aufbewahrungsanordnungen geerbt von: :relations',
    ],

    'implementation' => [
        'heading' => '5. Umsetzung',
        'deletion_heading' => 'Löschung',
        'deletion' => 'Datensätze werden nach Fristablauf endgültig gelöscht, auch solche, die zuvor nur als gelöscht markiert waren (Soft Delete). Ein Datensatz wird erst gelöscht, wenn die von ihm abhängigen Datensätze entfernt sind. Zeilen von Tabellen ohne Model werden stapelweise gelöscht.',
        'anonymisation_heading' => 'Anonymisierung',
        'anonymisation' => 'Datensätze mit der Endaktion „Anonymisieren“ behalten ihre Zeile, ihre personenbezogenen Daten werden aber einmalig entfernt. Danach werden sie nicht erneut verarbeitet.',
        'restriction_heading' => 'Einschränkung der Verarbeitung',
        'restriction' => 'Eingeschränkte Datensätze (Art. 18 DSGVO, § 35 BDSG) sind für die normale Verarbeitung der Anwendung nicht sichtbar. Sie erhalten nach Fristablauf ihre Endaktion.',
        'erasure_heading' => 'Löschanträge',
        'erasure' => 'Über einen Löschantrag (Art. 17 DSGVO) wird je Datensatz entschieden: Datensätze unter einer Aufbewahrungsanordnung bleiben erhalten; Datensätze, deren Frist noch nicht begonnen hat (z. B. laufender Vertrag), bleiben unangetastet und werden gemeldet; Datensätze, deren Regel die Aufbewahrung rechtfertigt, werden bis zum Fristablauf eingeschränkt; Datensätze mit abhängigen Datensätzen werden eingeschränkt, bis diese entfernt sind; alle übrigen erhalten sofort ihre Endaktion. Das Ergebnis kann für die Antwort an die betroffene Person verwendet werden.',
        'schedule_heading' => 'Ausführung',
        'schedule' => 'Fällige Datensätze verarbeitet der Befehl retention:run, den die Anwendung regelmäßig ausführt. Fristen werden in der Zeitzone :timezone berechnet. Vor jedem Lauf werden alle Festlegungen geprüft; ist eine davon ungültig, wird nichts geändert. Der Befehl retention:audit meldet Models und Tabellen ohne Löschregel.',
        'log_heading' => 'Löschprotokoll',
        'log' => 'Jede Löschung, Anonymisierung und Einschränkung wird als Nachweis protokolliert (Art. 5 Abs. 2 DSGVO): Datenart, Regel, Aktion, Auslöser, Zeitpunkt und eine gehashte Datensatz-ID. Der Hash ist mit einem Schlüssel gebildet: Ein bestimmter Datensatz lässt sich bei Bedarf nachweisen, die ID aber nicht aus dem Protokoll ablesen. Da der Verantwortliche den Schlüssel besitzt, sind die Einträge pseudonymisiert und bleiben personenbezogene Daten (Erwägungsgrund 26 DSGVO). Sie werden :period aufbewahrt und dann gelöscht. Zeilen von Tabellen ohne Model werden nur als Anzahl protokolliert.',
        'log_disabled' => 'Das Löschprotokoll ist deaktiviert.',
        'hold_heading' => 'Aufbewahrungsanordnung (Legal Hold)',
        'hold' => 'Solange eine Aufbewahrungsanordnung aktiv ist (z. B. laufendes Verfahren oder Betriebsprüfung), wird der Datensatz weder gelöscht noch anonymisiert. Eine Anordnung kann auch die von dem Datensatz abhängigen Datensätze schützen. Anordnungen nennen die Person, die sie erteilt hat; aufgehobene und abgelaufene Anordnungen werden nach :period gelöscht.',
    ],

    'exclusions' => [
        'heading' => '6. Ausnahmen',
        'intro' => 'Diese Models und Tabellen haben bewusst keine Aufbewahrungsregel.',
        'none' => 'Es gibt keine Ausnahmen.',
        'name' => 'Model oder Tabelle',
        'justification' => 'Begründung',
    ],

    'systems' => [
        'heading' => '7. Nicht erfasste Systeme',
        'text' => 'Die Anwendung sieht nur ihre eigene Datenbank. Andere Systeme müssen gesondert behandelt werden, insbesondere externe Dienste, Postfächer und Dateispeicher (Dateien werden nur entfernt, wenn die Anwendung das beim Löschen eines Datensatzes veranlasst). Backups: Gelöschte Daten bleiben in Backups, bis diese rotiert werden. Wird ein Backup eingespielt, muss der Löschlauf wiederholt werden, bevor die Daten erneut genutzt werden. Tragen Sie hier die Rotationsfristen ein.',
    ],

    'units' => [
        'year' => ':count Jahr|:count Jahre',
        'month' => ':count Monat|:count Monate',
        'day' => ':count Tag|:count Tage',
        'hour' => ':count Stunde|:count Stunden',
        'minute' => ':count Minute|:count Minuten',
        'second' => ':count Sekunde|:count Sekunden',
    ],

    'start_events' => [
        'collection' => 'Erhebung der Daten',
        'end_of_transaction' => 'Ende des Vorgangs',
        'end_of_relationship' => 'Ende der Beziehung',
    ],

    'counting' => [
        'end_of_year' => 'Ab Ende des Kalenderjahres des Startereignisses',
        'event' => 'Ab dem Startereignis',
    ],

    'actions' => [
        'delete' => 'Löschen',
        'anonymize' => 'Anonymisieren',
    ],

    'types' => [
        'legal' => 'Gesetzlich',
        'recommendation' => 'Empfehlung',
        'custom' => 'Vom Verantwortlichen festgelegt',
    ],
];
