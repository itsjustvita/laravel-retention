<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use ItsJustVita\Retention\Rules\RuleCatalogue;

function berlin(): RetentionCalculator
{
    return new RetentionCalculator('Europe/Berlin');
}

it('calculates the example from the specification', function () {
    $rule = app(RuleCatalogue::class)->get('ao_accounting_voucher');

    expect(berlin()->expiresAt(at('2025-03-15'), $rule, at('2030-01-01'))->toIso8601String())
        ->toBe('2034-01-01T00:00:00+01:00');
});

it('starts end-of-year periods at the end of the calendar year of the start event', function (string $event, string $expected) {
    expect(berlin()->expiresAt(at($event), makeRule('P8Y', 'end_of_year'), at('2030-01-01'))->toIso8601String())
        ->toBe($expected);
})->with([
    '1 January' => ['2025-01-01 00:00:00', '2034-01-01T00:00:00+01:00'],
    '31 December, last second' => ['2025-12-31 23:59:59.999999', '2034-01-01T00:00:00+01:00'],
    'first instant of the next year' => ['2026-01-01 00:00:00', '2035-01-01T00:00:00+01:00'],
    '29 February' => ['2024-02-29 12:00:00', '2033-01-01T00:00:00+01:00'],
]);

it('determines the calendar year in the retention timezone', function () {
    $event = CarbonImmutable::parse('2025-12-31 23:30:00', 'UTC'); // 00:30 on 1 January in Berlin
    $rule = makeRule('P8Y', 'end_of_year');

    expect(berlin()->expiresAt($event, $rule, at('2030-01-01'))->toIso8601String())
        ->toBe('2035-01-01T00:00:00+01:00')
        ->and((new RetentionCalculator('UTC'))->expiresAt($event, $rule, at('2030-01-01'))->toIso8601String())
        ->toBe('2034-01-01T00:00:00+00:00');
});

it('counts date-based periods from the event by calendar days (§§ 187, 188 BGB)', function (string $period, string $event, string $expected) {
    expect(berlin()->expiresAt(at($event), makeRule($period), at('2030-01-01'))->toIso8601String())
        ->toBe($expected);
})->with([
    '6 months' => ['P6M', '2025-03-15 14:00', '2025-09-16T00:00:00+02:00'],
    '7 days' => ['P7D', '2025-03-15 14:00', '2025-03-23T00:00:00+01:00'],
    '1 month from 31 January' => ['P1M', '2025-01-31 10:00', '2025-03-01T00:00:00+01:00'],
    '1 month from 28 February' => ['P1M', '2025-02-28 10:00', '2025-03-29T00:00:00+01:00'],
    '1 year from 29 February' => ['P1Y', '2024-02-29 10:00', '2025-03-01T00:00:00+01:00'],
    '7 days across the DST change' => ['P7D', '2025-03-28 10:00', '2025-04-05T00:00:00+02:00'],
    'zero days' => ['P0D', '2025-03-15 14:00', '2025-03-16T00:00:00+01:00'],
]);

it('counts time-based periods exactly', function () {
    // 72 real hours across the switch to daylight saving time on 30 March 2025.
    expect(berlin()->expiresAt(at('2025-03-29 12:00'), makeRule('PT72H'), at('2030-01-01'))->toIso8601String())
        ->toBe('2025-04-01T13:00:00+02:00');
});

it('applies the BEG IV transition by evaluation date', function (string $event, string $now, string $expected, bool $due) {
    $rule = app(RuleCatalogue::class)->get('ao_accounting_voucher');

    expect(berlin()->expiresAt(at($event), $rule, at($now))->toDateString())->toBe($expected)
        ->and(berlin()->isDue(at($event), $rule, at($now)))->toBe($due);
})->with([
    'before the transition, 10 years apply' => ['2015-06-01', '2024-06-01', '2026-01-01', false],
    'on the transition day, a 2015 voucher becomes deletable' => ['2015-06-01', '2025-01-01', '2025-01-01', true],
    'on the transition day, a 2016 voucher becomes deletable' => ['2016-06-01', '2025-01-01', '2025-01-01', true],
    'a voucher that expired under the old rule stays expired' => ['2014-06-01', '2025-01-01', '2025-01-01', true],
    'an older voucher keeps its original date' => ['2013-06-01', '2025-06-01', '2024-01-01', true],
    'after the transition, 8 years apply' => ['2020-06-01', '2025-06-01', '2029-01-01', false],
]);

it('keeps records expired under a shorter previous period expired', function (string $event, string $now, string $expected) {
    $rule = makeRule('P3Y', 'end_of_year', ['valid_from' => '2025-01-01', 'previous' => ['period' => 'P2Y']]);

    expect(berlin()->expiresAt(at($event), $rule, at($now))->toDateString())->toBe($expected);
})->with([
    'expired at the transition under the old period' => ['2022-06-01', '2025-06-01', '2025-01-01'],
    'still running at the transition, so the new period applies' => ['2023-06-01', '2025-06-01', '2027-01-01'],
    'before the transition' => ['2023-06-01', '2024-06-01', '2026-01-01'],
]);

it('uses the current time when no evaluation date is given', function () {
    $rule = makeRule('P8Y', 'end_of_year');

    $this->travelTo(at('2033-12-31 23:59:59'));
    expect(berlin()->isDue(at('2025-03-15'), $rule))->toBeFalse();

    $this->travelTo(at('2034-01-01 00:00:00'));
    expect(berlin()->isDue(at('2025-03-15'), $rule))->toBeTrue();
});

it('derives the cutoff for due records', function (string $period, string $counting, string $now, string $cutoff, bool $inclusive) {
    $constraint = berlin()->dueConstraint(makeRule($period, $counting), at($now));

    expect($constraint->cutoff->toIso8601String())->toBe($cutoff)
        ->and($constraint->inclusive)->toBe($inclusive);
})->with([
    'end of year, on the expiry instant' => ['P8Y', 'end_of_year', '2034-01-01 00:00:00', '2026-01-01T00:00:00+01:00', false],
    'end of year, one second before' => ['P8Y', 'end_of_year', '2033-12-31 23:59:59', '2025-01-01T00:00:00+01:00', false],
    'days from the event' => ['P7D', 'event', '2025-03-23 00:00:00', '2025-03-16T00:00:00+01:00', false],
    'hours from the event' => ['PT72H', 'event', '2025-04-01 13:00:00', '2025-03-29T12:00:00+01:00', true],
]);

it('derives a single cutoff across a transition', function () {
    $rule = app(RuleCatalogue::class)->get('ao_accounting_voucher');

    // Vouchers up to 2014 expired under the old 10 years, those up to 2016 under the new 8 years.
    expect(berlin()->dueConstraint($rule, at('2025-01-01'))->cutoff->toIso8601String())
        ->toBe('2017-01-01T00:00:00+01:00');
});

it('rejects an invalid timezone', function () {
    new RetentionCalculator('Mars/Olympus_Mons');
})->throws(InvalidArgumentException::class, 'Invalid retention timezone [Mars/Olympus_Mons]');
