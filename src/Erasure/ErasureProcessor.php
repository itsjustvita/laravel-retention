<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Erasure;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Actions\ActionExecutor;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use ItsJustVita\Retention\Concerns\Restrictable;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\ActionOutcome;
use ItsJustVita\Retention\Enums\ErasureBeforeStart;
use ItsJustVita\Retention\Enums\ErasureStatus;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Events\ErasureCompleted;
use ItsJustVita\Retention\Exceptions\MissingRetentionPolicy;
use ItsJustVita\Retention\Execution\DueRecords;
use ItsJustVita\Retention\Holds\LegalHolds;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Policies\PolicyValidator;
use ItsJustVita\Retention\RetentionPolicy;
use ItsJustVita\Retention\Rules\RetentionRule;
use ItsJustVita\Retention\Rules\RuleCatalogue;
use ItsJustVita\Retention\Support\RelationResolver;
use LogicException;

/**
 * Executes an erasure request (Art. 17 GDPR) for a subject and its related
 * records.
 *
 * Records are processed depth-first, the subject last, inside one
 * transaction. Per record, the first matching case applies:
 *  1. on legal hold                       -> kept
 *  2. start event not occurred            -> left untouched (unless the policy opts in)
 *  3. blocking rule, period still running -> restricted until it expires
 *  4. delete, blockedBy() not empty       -> restricted until the dependents are gone
 *  5. otherwise                           -> final action now
 */
final readonly class ErasureProcessor
{
    public function __construct(
        private PolicyRegistry $policies,
        private PolicyValidator $validator,
        private RuleCatalogue $rules,
        private RetentionCalculator $calculator,
        private DueRecords $dueRecords,
        private LegalHolds $holds,
        private ActionExecutor $executor,
        private Dispatcher $events,
    ) {}

    /**
     * @param  list<string>  $relations  relation paths, dot notation for nested relations
     */
    public function process(Model $subject, array $relations, bool $dryRun): ErasureReport
    {
        $records = $this->collect($subject, $this->tree($relations));

        $models = array_values(array_unique(array_map(fn (Model $record): string => $record::class, $records)));
        $covered = array_values(array_filter($models, $this->policies->has(...)));

        if (! $dryRun && count($covered) < count($models)) {
            throw MissingRetentionPolicy::for(implode(', ', array_diff($models, $covered)));
        }

        $this->validator->assertValid($covered);

        $now = CarbonImmutable::now($this->calculator->timezone());

        $handle = function () use ($records, $now, $dryRun): array {
            $deleted = [];
            $entries = [];

            foreach ($records as $record) {
                $entries[] = $this->policies->has($record::class)
                    ? $this->handle($record, $now, $dryRun, $deleted)
                    : new ErasureEntry($record::class, $this->key($record), ErasureStatus::NoPolicy);
            }

            return $entries;
        };

        /** @var list<ErasureEntry> $entries */
        $entries = $dryRun ? $handle() : $subject->getConnection()->transaction($handle);

        $report = new ErasureReport($subject::class, $this->key($subject), $now, $dryRun, $entries);

        if (! $dryRun) {
            $this->events->dispatch(new ErasureCompleted($report));
        }

        return $report;
    }

    /**
     * @param  array<string, true>  $deleted  keys of records deleted (or, in a dry run, planned to be deleted)
     */
    private function handle(Model $record, CarbonImmutable $now, bool $dryRun, array &$deleted): ErasureEntry
    {
        $policy = $this->policies->for($record::class);
        $rule = $this->rule($policy);
        $entry = fn (ErasureStatus $status, ?CarbonImmutable $scheduledFor = null, bool $waitsForDependents = false): ErasureEntry => new ErasureEntry(
            model: $record::class,
            key: $this->key($record),
            status: $status,
            category: $policy->category,
            label: $policy->label,
            finalAction: $policy->finalAction,
            scheduledFor: $scheduledFor,
            waitsForDependents: $waitsForDependents,
            erasureGround: $status === ErasureStatus::Restricted ? $rule->erasureGround : null,
        );

        if ($this->holds->isHeld($record, $now)) {
            return $entry(ErasureStatus::OnHold);
        }

        $start = $this->dueRecords->startEvent($record);

        if ($start === null && $policy->erasureBeforeStart === ErasureBeforeStart::Skip) {
            return $entry(ErasureStatus::StartEventPending);
        }

        if ($start !== null && $rule->blocksErasure && ! $this->calculator->isDue($start, $rule, $now)) {
            $this->restrict($record, $dryRun);

            return $entry(ErasureStatus::Restricted, $this->calculator->expiresAt($start, $rule, $now));
        }

        if ($policy->finalAction === Action::Delete && $this->hasRemainingDependents($record, $policy, $deleted)) {
            $this->restrict($record, $dryRun);

            return $entry(ErasureStatus::Restricted, waitsForDependents: true);
        }

        if ($dryRun) {
            if ($policy->finalAction === Action::Delete) {
                $deleted[$this->identity($record)] = true;

                return $entry(ErasureStatus::Deleted);
            }

            return $entry(ErasureStatus::Anonymized);
        }

        $outcome = $this->executor->apply($record, Trigger::ErasureRequest);

        if ($outcome === ActionOutcome::Deleted) {
            $deleted[$this->identity($record)] = true;
        }

        if ($outcome === ActionOutcome::Blocked) {
            $this->restrict($record, $dryRun);

            return $entry(ErasureStatus::Restricted, waitsForDependents: true);
        }

        return $entry(match ($outcome) {
            ActionOutcome::Deleted => ErasureStatus::Deleted,
            ActionOutcome::Anonymized, ActionOutcome::AlreadyAnonymized => ErasureStatus::Anonymized,
            ActionOutcome::OnHold => ErasureStatus::OnHold,
            ActionOutcome::Cancelled => ErasureStatus::Cancelled,
        });
    }

    /**
     * Whether a blockedBy() relation still holds a record that is not
     * (planned to be) deleted by this request.
     *
     * @param  array<string, true>  $deleted
     */
    private function hasRemainingDependents(Model $record, RetentionPolicy $policy, array $deleted): bool
    {
        foreach ($policy->blockingRelations as $name) {
            foreach (RelationResolver::resolve($record, $name)->withoutGlobalScopes()->get() as $dependent) {
                if (! isset($deleted[$this->identity($dependent)])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function restrict(Model $record, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        if (! in_array(Restrictable::class, class_uses_recursive($record), true) || ! method_exists($record, 'restrict')) {
            throw new LogicException('['.$record::class.'] must use the Restrictable trait to be restricted.');
        }

        $record->restrict(Trigger::ErasureRequest);
    }

    /**
     * Records in processing order: depth-first, every record after the
     * records related to it, each record once.
     *
     * @param  array<string, mixed>  $tree
     * @return list<Model>
     */
    private function collect(Model $subject, array $tree): array
    {
        $ordered = [];
        $seen = [];

        $visit = function (Model $record, array $tree) use (&$visit, &$ordered, &$seen): void {
            foreach ($tree as $name => $children) {
                foreach (RelationResolver::resolve($record, (string) $name)->withoutGlobalScopes()->get() as $related) {
                    $visit($related, is_array($children) ? $children : []);
                }
            }

            if (! isset($seen[$this->identity($record)])) {
                $seen[$this->identity($record)] = true;
                $ordered[] = $record;
            }
        };

        $visit($subject, $tree);

        return $ordered;
    }

    /**
     * Turns ['orders.invoices', 'notes'] into ['orders' => ['invoices' => []], 'notes' => []].
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function tree(array $paths): array
    {
        $tree = [];

        foreach ($paths as $path) {
            $branch = [];

            foreach (array_reverse(explode('.', $path)) as $segment) {
                $branch = [$segment => $branch];
            }

            $tree = array_replace_recursive($tree, $branch);
        }

        return $tree;
    }

    private function rule(RetentionPolicy $policy): RetentionRule
    {
        return $this->rules->get((string) $policy->ruleKey);
    }

    private function identity(Model $record): string
    {
        return $record::class.'#'.$this->key($record);
    }

    private function key(Model $record): int|string
    {
        $key = $record->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new LogicException('Erasure needs a scalar primary key on ['.$record::class.'].');
        }

        return $key;
    }
}
