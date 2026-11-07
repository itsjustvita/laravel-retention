<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Actions;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Concerns\Restrictable;
use ItsJustVita\Retention\Database\RetentionColumns;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\ActionOutcome;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Events\RecordAnonymized;
use ItsJustVita\Retention\Events\RecordDeleted;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\RetentionPolicy;

/**
 * Applies a record's final action (delete or anonymize) right away.
 *
 * Callers decide when a record is due and wrap the work in a transaction;
 * the executor only refuses to delete records whose blockedBy() relations
 * still hold records, and never anonymises a record twice.
 */
final readonly class ActionExecutor
{
    public function __construct(
        private PolicyRegistry $policies,
        private Dependencies $dependencies,
        private Dispatcher $events,
        private Container $container,
    ) {}

    public function apply(Model $record, Trigger $trigger): ActionOutcome
    {
        $policy = $this->policies->for($record::class);

        return match ($policy->finalAction) {
            Action::Delete => $this->delete($record, $policy, $trigger),
            Action::Anonymize => $this->anonymize($record, $policy, $trigger),
            null => throw new InvalidRetentionPolicy($record::class, ['no action set; call action() with delete or anonymize.']),
        };
    }

    private function delete(Model $record, RetentionPolicy $policy, Trigger $trigger): ActionOutcome
    {
        if ($this->dependencies->blocksDeletion($record, $policy)) {
            return ActionOutcome::Blocked;
        }

        // Hard delete; forceDelete() falls back to delete() without SoftDeletes.
        if ($record->forceDelete() === false) {
            return ActionOutcome::Cancelled;
        }

        $this->events->dispatch(new RecordDeleted($record, $policy, $trigger));

        return ActionOutcome::Deleted;
    }

    private function anonymize(Model $record, RetentionPolicy $policy, Trigger $trigger): ActionOutcome
    {
        if ($record->getAttribute(RetentionColumns::ANONYMIZED_AT) !== null) {
            return ActionOutcome::AlreadyAnonymized;
        }

        $this->runAnonymizer($record, $policy);

        $marker = [RetentionColumns::ANONYMIZED_AT => $record->freshTimestamp()];

        // The record no longer holds personal data, so the restriction is lifted.
        if (in_array(Restrictable::class, class_uses_recursive($record), true)) {
            $marker[RetentionColumns::RESTRICTED_AT] = null;
        }

        // Base query builder: the anonymizer owns updated_at, the marker does not touch it.
        $record->newModelQuery()->whereKey($record->getKey())->toBase()->update($marker);
        $record->forceFill($marker)->syncOriginalAttributes(array_keys($marker));

        $this->events->dispatch(new RecordAnonymized($record, $policy, $trigger));

        return ActionOutcome::Anonymized;
    }

    private function runAnonymizer(Model $record, RetentionPolicy $policy): void
    {
        $anonymizer = $policy->anonymizer;

        if ($anonymizer === null) {
            throw new InvalidRetentionPolicy($record::class, ['action anonymize requires an anonymizer; call anonymizeUsing().']);
        }

        if ($anonymizer instanceof Closure) {
            $anonymizer($record);

            return;
        }

        if (class_exists($anonymizer)) {
            $instance = $this->container->make($anonymizer);

            if (! is_callable($instance)) {
                throw new InvalidRetentionPolicy($record::class, ["anonymizer [{$anonymizer}] is not invokable; add an __invoke() method."]);
            }

            $instance($record);

            return;
        }

        $record->{$anonymizer}();
    }
}
