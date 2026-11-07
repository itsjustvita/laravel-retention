<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Rules;

/**
 * The rule catalogue shipped with the package.
 *
 * Not legal advice: the controller has to review every rule before relying
 * on it. Overrides and additions belong in config('retention.rules').
 */
final class ShippedRules
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            'ao_accounting_voucher' => [
                'label' => 'Accounting vouchers (e.g. invoices)',
                'period' => 'P8Y',
                'counting' => 'end_of_year',
                'legal_basis' => '§ 147 (1) no. 4, (3), (4) AO; § 257 (1) no. 4, (4), (5) HGB; § 14b (1) UStG',
                'type' => 'legal',
                'blocks_erasure' => true,
                'erasure_ground' => 'Art. 17 (3) (b) GDPR; § 35 (3) BDSG',
                'erasure_reason' => 'legal_duty',
                'valid_from' => '2025-01-01',
                'previous' => ['period' => 'P10Y'],
                'note' => 'Reduced from 10 to 8 years by the Fourth Bureaucracy Relief Act (Bürokratieentlastungsgesetz IV, BEG IV) for all vouchers whose period had not expired by 2024-12-31. Institutions under the KWG, insurers under the VAG and securities institutions under the WpIG keep 10 years (Art. 97 § 19a (3) EGAO; § 257 (4) HGB) and must override this rule. The period does not expire while the records matter for taxes whose assessment period is still open (§ 147 (3) AO). The automatic deletion does not detect this; place a legal hold in such cases.',
            ],

            'ao_books_financial_statements' => [
                'label' => 'Books, records, inventories and annual financial statements',
                'period' => 'P10Y',
                'counting' => 'end_of_year',
                'legal_basis' => '§ 147 (1) no. 1, (3), (4) AO; § 257 (1) no. 1, (4), (5) HGB',
                'type' => 'legal',
                'blocks_erasure' => true,
                'erasure_ground' => 'Art. 17 (3) (b) GDPR; § 35 (3) BDSG',
                'erasure_reason' => 'legal_duty',
                'note' => 'The period does not expire while the records matter for taxes whose assessment period is still open (§ 147 (3) AO). The automatic deletion does not detect this; place a legal hold in such cases.',
            ],

            'ao_business_correspondence' => [
                'label' => 'Business correspondence and other tax-relevant records',
                'period' => 'P6Y',
                'counting' => 'end_of_year',
                'legal_basis' => '§ 147 (1) nos. 2, 3, 5, (3), (4) AO; § 257 (1) nos. 2, 3, (4), (5) HGB',
                'type' => 'legal',
                'blocks_erasure' => true,
                'erasure_ground' => 'Art. 17 (3) (b) GDPR; § 35 (3) BDSG',
                'erasure_reason' => 'legal_duty',
                'note' => 'The period does not expire while the records matter for taxes whose assessment period is still open (§ 147 (3) AO). The automatic deletion does not detect this; place a legal hold in such cases.',
            ],

            'bgb_limitation_period' => [
                'label' => 'Contract data after the end of the contract',
                'period' => 'P3Y',
                'counting' => 'end_of_year',
                'legal_basis' => '§§ 195, 199 (1) BGB',
                'type' => 'legal',
                'blocks_erasure' => true,
                'erasure_ground' => 'Art. 17 (3) (e) GDPR',
                'erasure_reason' => 'legal_claims',
                'note' => 'Regular limitation period. Not a retention duty: the data is kept to establish, exercise or defend legal claims.',
            ],

            'rejected_application' => [
                'label' => 'Rejected job applications',
                'period' => 'P6M',
                'counting' => 'event',
                'legal_basis' => '§ 15 (4) AGG; § 61b (1) ArbGG',
                'type' => 'recommendation',
                'blocks_erasure' => true,
                'erasure_ground' => 'Art. 17 (3) (e) GDPR',
                'erasure_reason' => 'legal_claims',
                'note' => 'Two months to assert claims under § 15 (4) AGG plus three months to sue under § 61b (1) ArbGG, plus a buffer. Use the date of the rejection as the start event.',
            ],

            'server_logs' => [
                'label' => 'Server logs',
                'period' => 'P7D',
                'counting' => 'event',
                'legal_basis' => 'Art. 5 (1) (e), Art. 6 (1) (f) GDPR',
                'type' => 'recommendation',
                'blocks_erasure' => false,
                'note' => 'Common practice for IP addresses in web server logs. Adjust to the IT security concept.',
            ],

            'consent_proof' => [
                'label' => 'Proof of consent',
                'period' => 'P3Y',
                'counting' => 'end_of_year',
                'legal_basis' => 'Art. 7 (1) GDPR; §§ 195, 199 (1) BGB',
                'type' => 'recommendation',
                'blocks_erasure' => true,
                'erasure_ground' => 'Art. 17 (3) (e) GDPR',
                'erasure_reason' => 'legal_claims',
                'note' => 'Kept for the regular limitation period after the consent ended (withdrawal or end of processing).',
            ],

            'uwg_telephone_advertising_consent' => [
                'label' => 'Consent to telephone advertising (consumers)',
                'period' => 'P5Y',
                'counting' => 'event',
                'legal_basis' => '§ 7a UWG',
                'type' => 'legal',
                'blocks_erasure' => true,
                'erasure_ground' => 'Art. 17 (3) (b) GDPR',
                'erasure_reason' => 'legal_duty',
                'note' => 'Kept for five years from when consent was given and again after each use. Use the date of the last use as the start event.',
            ],
        ];
    }
}
