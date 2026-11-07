<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use ItsJustVita\Retention\Erasure;

dataset('methods that are not relations', [
    'Model::delete' => 'delete',
    'Model::forceDelete' => 'forceDelete',
    'Model::save' => 'save',
    'Model::touch' => 'touch',
    'restrict() of the package' => 'restrict',
    'releaseHold() of the package' => 'releaseHold',
]);

it('never invokes a method that is not a relation', function (string $method) {
    config(['retention.log.enabled' => false]);
    $customer = createCustomer(['contract_ended_at' => '2020-06-01 10:00:00']);
    $customer->placeHold('Pending litigation');
    $updatedAt = DB::table('customers')->value('updated_at');

    foreach (['execute', 'dryRun'] as $run) {
        expect(fn () => Erasure::for($customer)->including([$method])->{$run}())
            ->toThrow(InvalidArgumentException::class, "has no relation [{$method}]");
    }

    expect(DB::table('customers')->count())->toBe(1)
        ->and(DB::table('customers')->value('restricted_at'))->toBeNull()
        ->and(DB::table('customers')->value('updated_at'))->toBe($updatedAt)
        ->and($customer->fresh()?->isOnHold())->toBeTrue();
})->with('methods that are not relations');

it('does not create records while checking a relation name', function () {
    createCustomer();

    expect(fn () => Erasure::for(createCustomer())->including(['save'])->dryRun())
        ->toThrow(InvalidArgumentException::class);

    expect(DB::table('customers')->count())->toBe(2);
});
