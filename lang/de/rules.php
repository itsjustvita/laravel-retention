<?php

declare(strict_types=1);

return [
    'ao_accounting_voucher' => [
        'label' => 'Buchungsbelege (z. B. Rechnungen)',
        'legal_basis' => '§ 147 Abs. 1 Nr. 4, Abs. 3, 4 AO; § 257 Abs. 1 Nr. 4, Abs. 4, 5 HGB; § 14b Abs. 1 UStG',
        'erasure_ground' => 'Art. 17 Abs. 3 lit. b DSGVO; § 35 Abs. 3 BDSG',
        'note' => 'Durch das Bürokratieentlastungsgesetz IV (BEG IV) von 10 auf 8 Jahre verkürzt, für alle Belege, deren Frist bis einschließlich 31.12.2024 noch nicht abgelaufen war. Institute nach dem KWG, Versicherungen nach dem VAG und Wertpapierinstitute nach dem WpIG behalten 10 Jahre (Art. 97 § 19a Abs. 3 EGAO; § 257 Abs. 4 HGB) und müssen diese Regel überschreiben. Die Frist läuft nicht ab, solange die Unterlagen für Steuern von Bedeutung sind, deren Festsetzungsfrist noch nicht abgelaufen ist (§ 147 Abs. 3 AO). Die automatische Löschung erkennt das nicht; in solchen Fällen ist eine Aufbewahrungsanordnung anzulegen.',
    ],
    'ao_books_financial_statements' => [
        'label' => 'Bücher, Aufzeichnungen, Inventare und Jahresabschlüsse',
        'legal_basis' => '§ 147 Abs. 1 Nr. 1, Abs. 3, 4 AO; § 257 Abs. 1 Nr. 1, Abs. 4, 5 HGB',
        'erasure_ground' => 'Art. 17 Abs. 3 lit. b DSGVO; § 35 Abs. 3 BDSG',
        'note' => 'Die Frist läuft nicht ab, solange die Unterlagen für Steuern von Bedeutung sind, deren Festsetzungsfrist noch nicht abgelaufen ist (§ 147 Abs. 3 AO). Die automatische Löschung erkennt das nicht; in solchen Fällen ist eine Aufbewahrungsanordnung anzulegen.',
    ],
    'ao_business_correspondence' => [
        'label' => 'Geschäftsbriefe und sonstige steuerlich relevante Unterlagen',
        'legal_basis' => '§ 147 Abs. 1 Nr. 2, 3, 5, Abs. 3, 4 AO; § 257 Abs. 1 Nr. 2, 3, Abs. 4, 5 HGB',
        'erasure_ground' => 'Art. 17 Abs. 3 lit. b DSGVO; § 35 Abs. 3 BDSG',
        'note' => 'Die Frist läuft nicht ab, solange die Unterlagen für Steuern von Bedeutung sind, deren Festsetzungsfrist noch nicht abgelaufen ist (§ 147 Abs. 3 AO). Die automatische Löschung erkennt das nicht; in solchen Fällen ist eine Aufbewahrungsanordnung anzulegen.',
    ],
    'bgb_limitation_period' => [
        'label' => 'Vertragsdaten nach Vertragsende',
        'legal_basis' => '§§ 195, 199 Abs. 1 BGB',
        'erasure_ground' => 'Art. 17 Abs. 3 lit. e DSGVO',
        'note' => 'Regelmäßige Verjährungsfrist. Keine Aufbewahrungspflicht: Die Daten werden zur Geltendmachung, Ausübung oder Verteidigung von Rechtsansprüchen aufbewahrt.',
    ],
    'rejected_application' => [
        'label' => 'Abgelehnte Bewerbungen',
        'legal_basis' => '§ 15 Abs. 4 AGG; § 61b Abs. 1 ArbGG',
        'erasure_ground' => 'Art. 17 Abs. 3 lit. e DSGVO',
        'note' => 'Zwei Monate zur Geltendmachung nach § 15 Abs. 4 AGG, drei Monate zur Klageerhebung nach § 61b Abs. 1 ArbGG, zuzüglich Puffer. Startereignis ist das Datum der Absage.',
    ],
    'server_logs' => [
        'label' => 'Server-Logs',
        'legal_basis' => 'Art. 5 Abs. 1 lit. e, Art. 6 Abs. 1 lit. f DSGVO',
        'note' => 'Übliche Praxis für IP-Adressen in Webserver-Logs. An das IT-Sicherheitskonzept anpassen.',
    ],
    'consent_proof' => [
        'label' => 'Nachweis von Einwilligungen',
        'legal_basis' => 'Art. 7 Abs. 1 DSGVO; §§ 195, 199 Abs. 1 BGB',
        'erasure_ground' => 'Art. 17 Abs. 3 lit. e DSGVO',
        'note' => 'Aufbewahrung für die regelmäßige Verjährungsfrist nach Ende der Einwilligung (Widerruf oder Ende der Verarbeitung).',
    ],
    'uwg_telephone_advertising_consent' => [
        'label' => 'Einwilligung in Telefonwerbung (Verbraucher)',
        'legal_basis' => '§ 7a UWG',
        'erasure_ground' => 'Art. 17 Abs. 3 lit. b DSGVO',
        'note' => 'Aufbewahrung fünf Jahre ab Erteilung der Einwilligung und erneut nach jeder Verwendung. Startereignis ist das Datum der letzten Verwendung.',
    ],
];
