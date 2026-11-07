<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Execution;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ItsJustVita\Retention\Actions\ActionExecutor;
use ItsJustVita\Retention\Actions\Dependencies;
use ItsJustVita\Retention\Enums\ActionOutcome;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Events\RunCompleted;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Holds\LegalHolds;
use ItsJustVita\Retention\Log\DeletionLog;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Policies\PolicyValidator;
use ItsJustVita\Retention\Policies\TablePolicies;
use ItsJustVita\Retention\Policies\TablePolicy;

/**
 * Executes all due final actions: validates every policy first, then
 * processes the models in dependency order, in chunks with one transaction
 * per chunk. All records are evaluated against the same point in time.
 */
final readonly class RetentionRunner
{
    public function __construct(
        private ModelDiscovery $discovery,
        private PolicyValidator $validator,
        private PolicyRegistry $policies,
        private Dependencies $dependencies,
        private DueRecords $dueRecords,
        private DueTableRows $dueTableRows,
        private TablePolicies $tablePolicies,
        private ActionExecutor $executor,
        private LegalHolds $holds,
        private DeletionLog $log,
        private Dispatcher $events,
        private Repository $config,
    ) {}

    /**
     * @param  list<class-string<Model>>|null  $models  null for every model with a retention policy and every table policy
     *
     * @throws InvalidRetentionPolicy if any policy is invalid; nothing is changed then
     */
    public function run(?array $models = null, bool $dryRun = false, ?int $chunkSize = null): RunReport
    {
        $includeTables = $models === null;
        $models ??= $this->discovery->retainedModels();
        $this->validate($models, $includeTables);

        $now = CarbonImmutable::now();
        $chunkSize ??= $this->defaultChunkSize();

        $results = [];

        foreach ($this->dependencies->processingOrder($models) as $model) {
            $results[] = $dryRun ? $this->preview($model, $now) : $this->process($model, $now, $chunkSize);
        }

        foreach ($includeTables ? $this->tablePolicies->all() : [] as $policy) {
            $results[] = $dryRun ? $this->previewTable($policy, $now) : $this->processTable($policy, $now, $chunkSize);
        }

        $report = new RunReport(
            results: $results,
            dryRun: $dryRun,
            evaluatedAt: $now,
            purgedHolds: $dryRun ? 0 : $this->holds->purge($now),
            purgedLogEntries: $dryRun || ! $this->log->enabled() ? 0 : $this->log->purge($now),
        );
        $this->events->dispatch(new RunCompleted($report));

        return $report;
    }

    /**
     * @param  list<class-string<Model>>  $models
     *
     * @throws InvalidRetentionPolicy with the violations of all models
     */
    public function validate(array $models, bool $includeTables = false): void
    {
        $this->validator->assertValid($models, $includeTables);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function process(string $model, CarbonImmutable $now, int $chunkSize): ModelRunResult
    {
        $tally = ['deleted' => 0, 'anonymized' => 0, 'cancelled' => 0, 'skipped' => 0];
        $connection = (new $model)->getConnection();

        $this->dueRecords->query($model, $now)->chunkById($chunkSize, function (Collection $records) use (&$tally, $connection, $now): void {
            $connection->transaction(function () use ($records, &$tally, $now): void {
                foreach ($records as $record) {
                    if (! $this->dueRecords->isDue($record, $now)) {
                        $tally['skipped']++;

                        continue;
                    }

                    match ($this->executor->apply($record, Trigger::Schedule)) {
                        ActionOutcome::Deleted => $tally['deleted']++,
                        ActionOutcome::Anonymized => $tally['anonymized']++,
                        ActionOutcome::Cancelled => $tally['cancelled']++,
                        // Changed after the selection; blocked and held records are counted below.
                        ActionOutcome::Blocked, ActionOutcome::OnHold, ActionOutcome::AlreadyAnonymized => null,
                    };
                }
            });
        });

        $remaining = $this->remaining($model, $now);

        return new ModelRunResult(
            model: $model,
            category: $this->policies->for($model)->category,
            deleted: $tally['deleted'],
            anonymized: $tally['anonymized'],
            blocked: $remaining['blocked'],
            onHold: $remaining['onHold'],
            cancelled: $tally['cancelled'],
            skipped: $tally['skipped'],
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function preview(string $model, CarbonImmutable $now): ModelRunResult
    {
        $remaining = $this->remaining($model, $now);

        return new ModelRunResult(
            model: $model,
            category: $this->policies->for($model)->category,
            due: $remaining['due'],
            blocked: $remaining['blocked'],
            onHold: $remaining['onHold'],
        );
    }

    private function processTable(TablePolicy $policy, CarbonImmutable $now, int $chunkSize): ModelRunResult
    {
        $total = 0;

        do {
            $deleted = $this->dueTableRows->deleteBatch($policy, $now, $chunkSize);
            $total += $deleted;
        } while ($deleted >= $chunkSize);

        return new ModelRunResult(model: $policy->table, category: $policy->category, deleted: $total);
    }

    private function previewTable(TablePolicy $policy, CarbonImmutable $now): ModelRunResult
    {
        return new ModelRunResult(
            model: $policy->table,
            category: $policy->category,
            due: $this->dueTableRows->query($policy, $now)->count(),
        );
    }

    /**
     * Splits the records whose period has expired into those that can be
     * processed, those blocked by blockedBy() and those on legal hold (a held
     * record counts as held, even if it is also blocked).
     *
     * @param  class-string<Model>  $model
     * @return array{due: int, blocked: int, onHold: int}
     */
    public function remaining(string $model, CarbonImmutable $now): array
    {
        $expired = $this->dueRecords->query($model, $now, excludeBlocked: false, excludeHeld: false)->count();
        $notHeld = $this->dueRecords->query($model, $now, excludeBlocked: false)->count();
        $due = $this->dueRecords->query($model, $now)->count();

        return ['due' => $due, 'blocked' => $notHeld - $due, 'onHold' => $expired - $notHeld];
    }

    private function defaultChunkSize(): int
    {
        $size = $this->config->get('retention.chunk_size', 500);

        if (! is_int($size) || $size < 1) {
            throw new InvalidArgumentException('[retention.chunk_size] must be a positive integer.');
        }

        return $size;
    }
}
