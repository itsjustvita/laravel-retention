<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ItsJustVita\Retention\Rules\RetentionRule;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;
use ItsJustVita\Retention\Tests\Fixtures\Models\Order;
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

/**
 * @param  array<string, mixed>  $attributes
 */
function createCustomer(array $attributes = []): Customer
{
    return Customer::create([
        'name' => 'Erika Mustermann',
        'email' => 'erika@example.com',
        'phone' => '+49 30 1234567',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createOrder(Customer $customer, array $attributes = []): Order
{
    return Order::create(['customer_id' => $customer->id, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createInvoice(Customer $customer, array $attributes = []): Invoice
{
    return Invoice::create(['customer_id' => $customer->id, 'issued_at' => '2025-03-15', ...$attributes]);
}
