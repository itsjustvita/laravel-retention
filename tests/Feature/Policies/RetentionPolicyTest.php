<?php

declare(strict_types=1);

use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\ErasureBeforeStart;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Tests\Fixtures\CustomerAnonymizer;

it('collects the declaration', function () {
    $policy = RetentionPolicy::category('customer_master_data', 'Customer master data')
        ->description('Names and contact details of customers.')
        ->rule('bgb_limitation_period')
        ->startsAt('contract_ended_at', StartEvent::EndOfRelationship)
        ->action(Action::Anonymize)
        ->anonymizeUsing(CustomerAnonymizer::class)
        ->blockedBy('orders')
        ->inheritsHoldsFrom(['account', 'account'])
        ->onErasureBeforeStart(ErasureBeforeStart::Apply)
        ->responsible('Sales');

    expect($policy->category)->toBe('customer_master_data')
        ->and($policy->label)->toBe('Customer master data')
        ->and($policy->description)->toBe('Names and contact details of customers.')
        ->and($policy->ruleKey)->toBe('bgb_limitation_period')
        ->and($policy->startColumn)->toBe('contract_ended_at')
        ->and($policy->startEvent)->toBe(StartEvent::EndOfRelationship)
        ->and($policy->finalAction)->toBe(Action::Anonymize)
        ->and($policy->anonymizer)->toBe(CustomerAnonymizer::class)
        ->and($policy->blockingRelations)->toBe(['orders'])
        ->and($policy->holdRelations)->toBe(['account'])
        ->and($policy->erasureBeforeStart)->toBe(ErasureBeforeStart::Apply)
        ->and($policy->responsibleUnit)->toBe('Sales');
});

it('has no defaults for the required parts', function () {
    $policy = RetentionPolicy::category('logs', 'Logs');

    expect($policy->ruleKey)->toBeNull()
        ->and($policy->startColumn)->toBeNull()
        ->and($policy->startEvent)->toBeNull()
        ->and($policy->finalAction)->toBeNull()
        ->and($policy->responsibleUnit)->toBeNull()
        ->and($policy->anonymizer)->toBeNull()
        ->and($policy->blockingRelations)->toBe([])
        ->and($policy->holdRelations)->toBe([])
        ->and($policy->erasureBeforeStart)->toBe(ErasureBeforeStart::Skip);
});

it('accepts enum values as strings', function () {
    $policy = RetentionPolicy::category('logs', 'Logs')
        ->startsAt('logged_at', 'collection')
        ->action('delete')
        ->onErasureBeforeStart('apply');

    expect($policy->startEvent)->toBe(StartEvent::Collection)
        ->and($policy->finalAction)->toBe(Action::Delete)
        ->and($policy->erasureBeforeStart)->toBe(ErasureBeforeStart::Apply);
});

it('rejects unknown enum values', function (Closure $declare, string $message) {
    expect(fn () => $declare(RetentionPolicy::category('logs', 'Logs')))
        ->toThrow(InvalidRetentionPolicy::class, $message);
})->with([
    'action' => [fn (RetentionPolicy $p) => $p->action('remove'), 'unknown action [remove]; expected one of [delete, anonymize]'],
    'start event' => [fn (RetentionPolicy $p) => $p->startsAt('logged_at', 'created'), 'unknown start event [created]; expected one of [collection, end_of_transaction, end_of_relationship]'],
    'erasure behaviour' => [fn (RetentionPolicy $p) => $p->onErasureBeforeStart('delete'), 'unknown erasure behaviour [delete]; expected one of [skip, apply]'],
]);
