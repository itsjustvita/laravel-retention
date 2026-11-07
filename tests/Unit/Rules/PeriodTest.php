<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ItsJustVita\Retention\Rules\Period;

it('parses date-based periods into months and days', function (string $iso, int $months, int $days) {
    $period = Period::parse($iso);

    expect($period->iso)->toBe($iso)
        ->and($period->months)->toBe($months)
        ->and($period->days)->toBe($days)
        ->and($period->seconds)->toBe(0)
        ->and($period->isTimeBased())->toBeFalse();
})->with([
    '8 years' => ['P8Y', 96, 0],
    '6 months' => ['P6M', 6, 0],
    '1 year and 6 months' => ['P1Y6M', 18, 0],
    '7 days' => ['P7D', 0, 7],
    '2 weeks' => ['P2W', 0, 14],
    'zero' => ['P0D', 0, 0],
]);

it('parses time-based periods into seconds', function () {
    $period = Period::parse('PT72H');

    expect($period->seconds)->toBe(259_200)
        ->and($period->months)->toBe(0)
        ->and($period->days)->toBe(0)
        ->and($period->isTimeBased())->toBeTrue();
});

it('rejects invalid periods', function (string $iso) {
    Period::parse($iso);
})->throws(InvalidArgumentException::class)->with([
    'not ISO 8601' => '8 years',
    'empty' => '',
    'mixed date and time' => 'P1DT12H',
]);

it('adds months without overflowing into the next month', function (string $date, string $iso, string $expected) {
    expect(Period::parse($iso)->addTo(CarbonImmutable::parse($date))->toDateString())->toBe($expected);
})->with([
    '31 Jan + 1 month' => ['2025-01-31', 'P1M', '2025-02-28'],
    '31 Jan + 1 month in a leap year' => ['2024-01-31', 'P1M', '2024-02-29'],
    '29 Feb + 1 year' => ['2024-02-29', 'P1Y', '2025-02-28'],
    '31 Aug + 6 months' => ['2025-08-31', 'P6M', '2026-02-28'],
    '15 Mar + 7 days' => ['2025-03-15', 'P7D', '2025-03-22'],
]);

it('subtracts months without overflowing', function () {
    expect(Period::parse('P1M')->subtractFrom(CarbonImmutable::parse('2025-03-31'))->toDateString())
        ->toBe('2025-02-28');
});
