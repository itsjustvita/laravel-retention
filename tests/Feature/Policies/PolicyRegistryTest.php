<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Exceptions\MissingRetentionPolicy;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;
use ItsJustVita\Retention\Tests\Fixtures\Models\Invoice;

it('resolves a policy declared in retentionPolicy()', function () {
    expect(app(PolicyRegistry::class)->for(Customer::class)->category)->toBe('customer_master_data');
});

it('resolves a policy declared with the #[Retention] attribute', function () {
    $policy = app(PolicyRegistry::class)->for(Invoice::class);

    expect($policy->category)->toBe('invoices')
        ->and($policy->label)->toBe('Customer invoices')
        ->and($policy->ruleKey)->toBe('ao_accounting_voucher')
        ->and($policy->startColumn)->toBe('issued_at')
        ->and($policy->startEvent)->toBe(StartEvent::EndOfTransaction)
        ->and($policy->finalAction)->toBe(Action::Delete)
        ->and($policy->holdRelations)->toBe(['customer'])
        ->and($policy->responsibleUnit)->toBe('Accounting');
});

it('builds each policy only once', function () {
    $registry = app(PolicyRegistry::class);

    expect($registry->for(Customer::class))->toBe($registry->for(Customer::class));
});

it('knows which classes can have a policy', function (string $class, bool $expected) {
    expect(app(PolicyRegistry::class)->has($class))->toBe($expected);
})->with([
    'model with HasRetention' => [Customer::class, true],
    'model without HasRetention' => [User::class, false],
    'not a model' => [stdClass::class, false],
    'not a class' => ['NoSuchClass', false],
]);

it('fails for a model without HasRetention', function () {
    app(PolicyRegistry::class)->for(User::class);
})->throws(MissingRetentionPolicy::class, 'has no retention policy');

it('fails for a model with HasRetention but neither a method nor an attribute', function () {
    $model = new class extends Model
    {
        use HasRetention;
    };

    app(PolicyRegistry::class)->for($model::class);
})->throws(MissingRetentionPolicy::class, 'define retentionPolicy() or add a #[Retention] attribute');
