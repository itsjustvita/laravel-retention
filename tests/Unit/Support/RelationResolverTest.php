<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\HasMany;
use ItsJustVita\Retention\Support\RelationResolver;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\PolicyProbe;

beforeEach(function () {
    PolicyProbe::$probeTable = 'customers';
    PolicyProbe::$untypedCalls = [];
});

it('resolves relations with and without a return type', function (string $name) {
    expect(RelationResolver::violation(new PolicyProbe, $name))->toBeNull()
        ->and(RelationResolver::resolve(new PolicyProbe, $name))->toBeInstanceOf(HasMany::class);
})->with(['typed' => 'invoices', 'untyped' => 'untypedInvoices']);

it('binds the relation to the given record', function () {
    $customer = createCustomer();

    expect(RelationResolver::resolve($customer, 'invoices')->getParentKey())->toBe($customer->id);
});

it('explains why a name is not a relation', function (string $name, string $message) {
    expect(RelationResolver::violation(new PolicyProbe, $name))->toBe($message);
})->with([
    'unknown' => ['payments', 'relation [payments] does not exist on the model.'],
    'typed as something else' => ['notARelation', '[notARelation] is not an Eloquent relation.'],
    'method of the base model' => ['delete', '[delete] is not an Eloquent relation.'],
    'static method' => ['retentionPolicy', '[retentionPolicy] is not an Eloquent relation.'],
    'required parameters' => ['placeHold', '[placeHold] is not an Eloquent relation.'],
    'untyped, returns something else' => ['untypedNotARelation', '[untypedNotARelation] is not an Eloquent relation.'],
]);

it('rejects names of the base model even if a model overrides them', function () {
    $model = new class extends Customer
    {
        public function save(array $options = [])
        {
            throw new LogicException('save() must not be called.');
        }
    };

    expect(RelationResolver::violation($model, 'save'))->toBe('[save] is not an Eloquent relation.');
});

it('tries untyped methods only on a blank instance, never on the record', function () {
    $probe = PolicyProbe::query()->findOrFail(createCustomer()->id);

    expect(fn () => RelationResolver::resolve($probe, 'untypedNotARelation'))
        ->toThrow(InvalidArgumentException::class, 'has no relation [untypedNotARelation]')
        ->and(PolicyProbe::$untypedCalls)->toBe([false]);
});

it('reports methods that fail on a blank instance', function () {
    $model = new class extends Customer
    {
        public function broken()
        {
            throw new RuntimeException('needs a loaded record');
        }
    };

    expect(RelationResolver::violation($model, 'broken'))
        ->toBe('relation [broken] could not be resolved: needs a loaded record');
});
