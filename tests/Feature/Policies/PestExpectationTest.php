<?php

declare(strict_types=1);

use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;
use PHPUnit\Framework\ExpectationFailedException;

it('passes for a valid policy', function () {
    expect(Customer::class)->toHaveValidRetentionPolicy();
});

it('fails with the violations for an invalid policy', function () {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$policy = fn () => RetentionPolicy::category('probe', 'Probe');

    expect(fn () => expect(PolicyProbe::class)->toHaveValidRetentionPolicy())
        ->toThrow(ExpectationFailedException::class, 'no rule set; call rule().');
});
