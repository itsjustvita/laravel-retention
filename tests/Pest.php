<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ItsJustVita\Retention\Rules\RetentionRule;
use ItsJustVita\Retention\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * Builds a custom retention rule for tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeRule(string $period, string $counting = 'event', array $overrides = []): RetentionRule
{
    return RetentionRule::fromArray('test', array_replace([
        'label' => 'Test rule',
        'period' => $period,
        'counting' => $counting,
        'legal_basis' => 'Test basis',
        'type' => 'custom',
        'blocks_erasure' => false,
    ], $overrides));
}

/**
 * Parses a local date/time in the given timezone (Europe/Berlin by default).
 */
function at(string $datetime, string $timezone = 'Europe/Berlin'): CarbonImmutable
{
    return CarbonImmutable::parse($datetime, $timezone);
}
