<?php

declare(strict_types=1);

use ItsJustVita\Retention\Enums\Counting;
use ItsJustVita\Retention\Enums\RuleType;
use ItsJustVita\Retention\Exceptions\InvalidRuleDefinition;
use ItsJustVita\Retention\Exceptions\UnknownRule;
use ItsJustVita\Retention\Rules\RuleCatalogue;
use ItsJustVita\Retention\Rules\ShippedRules;

it('ships the rule catalogue', function (string $key, string $period, Counting $counting, RuleType $type, bool $blocksErasure) {
    $rule = app(RuleCatalogue::class)->get($key);

    expect($rule->period->iso)->toBe($period)
        ->and($rule->counting)->toBe($counting)
        ->and($rule->type)->toBe($type)
        ->and($rule->blocksErasure)->toBe($blocksErasure);
})->with([
    'ao_accounting_voucher' => ['ao_accounting_voucher', 'P8Y', Counting::EndOfYear, RuleType::Legal, true],
    'ao_books_financial_statements' => ['ao_books_financial_statements', 'P10Y', Counting::EndOfYear, RuleType::Legal, true],
    'ao_business_correspondence' => ['ao_business_correspondence', 'P6Y', Counting::EndOfYear, RuleType::Legal, true],
    'bgb_limitation_period' => ['bgb_limitation_period', 'P3Y', Counting::EndOfYear, RuleType::Legal, true],
    'rejected_application' => ['rejected_application', 'P6M', Counting::Event, RuleType::Recommendation, true],
    'server_logs' => ['server_logs', 'P7D', Counting::Event, RuleType::Recommendation, false],
    'consent_proof' => ['consent_proof', 'P3Y', Counting::EndOfYear, RuleType::Recommendation, true],
    'uwg_telephone_advertising_consent' => ['uwg_telephone_advertising_consent', 'P5Y', Counting::Event, RuleType::Legal, true],
]);

it('ships exactly the documented rules, sorted by key', function () {
    expect(array_keys(app(RuleCatalogue::class)->all()))->toBe([
        'ao_accounting_voucher',
        'ao_books_financial_statements',
        'ao_business_correspondence',
        'bgb_limitation_period',
        'consent_proof',
        'rejected_application',
        'server_logs',
        'uwg_telephone_advertising_consent',
    ]);
});

it('gives every shipped rule a legal basis and, if it blocks erasure, an erasure ground', function () {
    foreach (app(RuleCatalogue::class)->all() as $rule) {
        expect($rule->legalBasis)->not->toBeEmpty();

        if ($rule->blocksErasure) {
            expect($rule->erasureGround)->not->toBeEmpty();
        }
    }
});

it('applies the BEG IV transition to accounting vouchers', function () {
    $rule = app(RuleCatalogue::class)->get('ao_accounting_voucher');

    expect($rule->validFrom)->toBe('2025-01-01')
        ->and($rule->previous?->period->iso)->toBe('P10Y');
});

it('merges config overrides into the shipped rule', function () {
    config(['retention.rules' => ['server_logs' => ['period' => 'P14D']]]);

    $rule = app(RuleCatalogue::class)->get('server_logs');

    expect($rule->period->iso)->toBe('P14D')
        ->and($rule->legalBasis)->toBe(ShippedRules::definitions()['server_logs']['legal_basis']);
});

it('lets supervised institutions move the BEG IV transition date', function () {
    config(['retention.rules' => ['ao_accounting_voucher' => ['valid_from' => '2026-01-01']]]);

    $rule = app(RuleCatalogue::class)->get('ao_accounting_voucher');

    expect($rule->validFrom)->toBe('2026-01-01')
        ->and($rule->previous?->period->iso)->toBe('P10Y');
});

it('removes a shipped transition when overridden with null', function () {
    config(['retention.rules' => ['ao_accounting_voucher' => ['valid_from' => null, 'previous' => null]]]);

    expect(app(RuleCatalogue::class)->get('ao_accounting_voucher')->previous)->toBeNull();
});

it('adds custom rules from config', function () {
    config(['retention.rules' => ['newsletter_tracking' => [
        'label' => 'Newsletter tracking data',
        'period' => 'P1Y',
        'counting' => 'event',
        'legal_basis' => 'Art. 6 (1) (a) GDPR',
        'type' => 'custom',
        'blocks_erasure' => false,
    ]]]);

    $catalogue = app(RuleCatalogue::class);

    expect($catalogue->has('newsletter_tracking'))->toBeTrue()
        ->and($catalogue->get('newsletter_tracking')->type)->toBe(RuleType::Custom);
});

it('throws for unknown rule keys', function () {
    app(RuleCatalogue::class)->get('does_not_exist');
})->throws(UnknownRule::class, 'Unknown retention rule [does_not_exist]');

it('rejects invalid rule definitions from config', function () {
    config(['retention.rules' => ['server_logs' => ['period' => 'seven days']]]);

    app(RuleCatalogue::class);
})->throws(InvalidRuleDefinition::class, 'Retention rule [server_logs]: [period]');

it('rejects a rule override that is not an array', function () {
    config(['retention.rules' => ['server_logs' => 'P14D']]);

    app(RuleCatalogue::class);
})->throws(InvalidRuleDefinition::class, 'Retention rule [server_logs]: the definition must be an array');
