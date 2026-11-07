<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use Random\Engine\Mt19937;
use Random\Randomizer;

dataset('rule shapes', [
    '8 years from end of year' => ['P8Y', 'end_of_year', []],
    '6 months from end of year' => ['P6M', 'end_of_year', []],
    '6 months from event' => ['P6M', 'event', []],
    '1 month from event' => ['P1M', 'event', []],
    '7 days from event' => ['P7D', 'event', []],
    '0 days from event' => ['P0D', 'event', []],
    '1 month and 15 days from event' => ['P1M15D', 'event', []],
    '11 months and 30 days from end of year' => ['P11M30D', 'end_of_year', []],
    '2 years, 1 month and 1 day from event' => ['P2Y1M1D', 'event', []],
    '72 hours from event' => ['PT72H', 'event', []],
    'shortened from 10 to 8 years' => ['P8Y', 'end_of_year', ['valid_from' => '2025-01-01', 'previous' => ['period' => 'P10Y']]],
    'lengthened from 2 to 3 years' => ['P3Y', 'end_of_year', ['valid_from' => '2025-01-01', 'previous' => ['period' => 'P2Y']]],
    'counting changed from event to end of year' => ['P1Y', 'end_of_year', ['valid_from' => '2025-07-01', 'previous' => ['period' => 'P6M', 'counting' => 'event']]],
    'two transitions' => ['P6Y', 'end_of_year', ['valid_from' => '2026-01-01', 'previous' => ['period' => 'P8Y', 'valid_from' => '2025-01-01', 'previous' => ['period' => 'P10Y']]]],
]);

dataset('timezones', [
    'Europe/Berlin',
    'UTC',
    'America/Santiago', // switches daylight saving time at midnight
]);

it('selects exactly the start events whose period has expired', function (string $period, string $counting, array $overrides, string $timezone) {
    $rule = makeRule($period, $counting, $overrides);
    $calculator = new RetentionCalculator($timezone);
    $random = new Randomizer(new Mt19937(crc32($period.$counting.serialize($overrides).$timezone)));

    $from = CarbonImmutable::parse('2010-01-01', 'UTC')->getTimestamp();
    $to = CarbonImmutable::parse('2040-01-01', 'UTC')->getTimestamp();
    $randomInstant = fn (): CarbonImmutable => CarbonImmutable::createFromTimestamp($random->getInt($from, $to), $timezone);

    $fixedNows = array_map(fn (string $date) => CarbonImmutable::parse($date, $timezone), [
        '2024-12-31 23:59:59.999999',
        '2025-01-01 00:00:00',
        '2025-07-01 00:00:00',
        '2026-01-01 00:00:00',
    ]);

    for ($i = 0; $i < 100; $i++) {
        $event = match ($i % 4) {
            0 => CarbonImmutable::parse($random->getInt(2010, 2039).'-12-31 23:59:59.999999', $timezone),
            1 => CarbonImmutable::parse($random->getInt(2010, 2039).'-01-01 00:00:00', $timezone),
            default => $randomInstant(),
        };

        $expiry = $calculator->expiresAt($event, $rule, $randomInstant());
        $nows = [...$fixedNows, $expiry, $expiry->subMicrosecond(), $randomInstant()];
        usort($nows, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        $wasDue = false;

        foreach ($nows as $now) {
            $due = $calculator->isDue($event, $rule, $now);
            $context = sprintf('event %s, now %s', $event->format('Y-m-d H:i:s.u T'), $now->format('Y-m-d H:i:s.u T'));

            expect($calculator->dueConstraint($rule, $now)->isSatisfiedBy($event))
                ->toBe($due, "dueConstraint() disagrees with expiresAt(): {$context}")
                ->and($due || ! $wasDue)
                ->toBeTrue("A due record became not due again: {$context}");

            $wasDue = $due;
        }
    }
})->with('rule shapes')->with('timezones');
