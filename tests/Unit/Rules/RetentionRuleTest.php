<?php

declare(strict_types=1);

use ItsJustVita\Retention\Enums\Counting;
use ItsJustVita\Retention\Enums\RuleType;
use ItsJustVita\Retention\Exceptions\InvalidRuleDefinition;
use ItsJustVita\Retention\Rules\RetentionRule;

it('builds a rule from a definition', function () {
    $rule = RetentionRule::fromArray('ao_accounting_voucher', [
        'label' => 'Accounting vouchers',
        'period' => 'P8Y',
        'counting' => 'end_of_year',
        'legal_basis' => '§ 147 AO',
        'type' => 'legal',
        'blocks_erasure' => true,
        'erasure_ground' => 'Art. 17 (3) (b) GDPR',
        'valid_from' => '2025-01-01',
        'previous' => ['period' => 'P10Y'],
        'note' => 'BEG IV',
        'as_of' => '2026-11-07',
    ]);

    expect($rule->key)->toBe('ao_accounting_voucher')
        ->and($rule->label)->toBe('Accounting vouchers')
        ->and($rule->period->iso)->toBe('P8Y')
        ->and($rule->counting)->toBe(Counting::EndOfYear)
        ->and($rule->legalBasis)->toBe('§ 147 AO')
        ->and($rule->type)->toBe(RuleType::Legal)
        ->and($rule->blocksErasure)->toBeTrue()
        ->and($rule->erasureGround)->toBe('Art. 17 (3) (b) GDPR')
        ->and($rule->validFrom)->toBe('2025-01-01')
        ->and($rule->note)->toBe('BEG IV')
        ->and($rule->asOf)->toBe('2026-11-07');
});

it('leaves optional fields empty', function () {
    $rule = makeRule('P7D');

    expect($rule->erasureGround)->toBeNull()
        ->and($rule->validFrom)->toBeNull()
        ->and($rule->previous)->toBeNull()
        ->and($rule->note)->toBeNull()
        ->and($rule->asOf)->toBeNull();
});

it('builds the previous version from the current values', function () {
    $rule = makeRule('P8Y', 'end_of_year', [
        'valid_from' => '2025-01-01',
        'previous' => ['period' => 'P10Y'],
    ]);

    expect($rule->previous)->not->toBeNull()
        ->and($rule->previous?->period->iso)->toBe('P10Y')
        ->and($rule->previous?->counting)->toBe(Counting::EndOfYear)
        ->and($rule->previous?->legalBasis)->toBe($rule->legalBasis)
        ->and($rule->previous?->validFrom)->toBeNull()
        ->and($rule->previous?->previous)->toBeNull();
});

it('supports a chain of previous versions', function () {
    $rule = makeRule('P6Y', 'end_of_year', [
        'valid_from' => '2026-01-01',
        'previous' => [
            'period' => 'P8Y',
            'valid_from' => '2025-01-01',
            'previous' => ['period' => 'P10Y'],
        ],
    ]);

    expect($rule->previous?->period->iso)->toBe('P8Y')
        ->and($rule->previous?->validFrom)->toBe('2025-01-01')
        ->and($rule->previous?->previous?->period->iso)->toBe('P10Y');
});

it('rejects invalid definitions', function (array $overrides, string $message) {
    expect(fn () => makeRule('P8Y', 'end_of_year', $overrides))
        ->toThrow(InvalidRuleDefinition::class, $message);
})->with([
    'unknown key' => [['starts' => 'end_of_year'], 'Retention rule [test]: unknown keys [starts]'],
    'empty label' => [['label' => ''], '[label] must be a non-empty string'],
    'missing legal basis' => [['legal_basis' => null], '[legal_basis] must be a non-empty string'],
    'invalid period' => [['period' => '8 years'], '[period]'],
    'invalid counting' => [['counting' => 'yearly'], '[counting] must be one of [end_of_year, event]'],
    'invalid type' => [['type' => 'law'], '[type] must be one of [legal, recommendation, custom]'],
    'non-boolean blocks_erasure' => [['blocks_erasure' => 'yes'], '[blocks_erasure] must be a boolean'],
    'blocking rule without ground' => [['blocks_erasure' => true], '[erasure_ground] is required when [blocks_erasure] is true'],
    'time-based period counted from end of year' => [['period' => 'PT72H'], '[period] is time-based and requires [counting] event'],
    'non-date valid_from' => [['valid_from' => '01.01.2025'], '[valid_from] must be a date in the format YYYY-MM-DD'],
    'impossible valid_from' => [['valid_from' => '2025-02-30'], '[valid_from] must be a date in the format YYYY-MM-DD'],
    'non-date as_of' => [['as_of' => 'today'], '[as_of] must be a date in the format YYYY-MM-DD'],
    'non-string note' => [['note' => 123], '[note] must be a string'],
    'previous without valid_from' => [['previous' => ['period' => 'P10Y']], '[previous] requires [valid_from]'],
    'previous not an array' => [['valid_from' => '2025-01-01', 'previous' => 'P10Y'], '[previous] must be an array'],
    'invalid previous period' => [['valid_from' => '2025-01-01', 'previous' => ['period' => 'ten years']], '[previous] [period]'],
    'previous not older' => [
        ['valid_from' => '2025-01-01', 'previous' => ['period' => 'P10Y', 'valid_from' => '2025-01-01']],
        '[previous][valid_from] must be before [valid_from]',
    ],
]);
