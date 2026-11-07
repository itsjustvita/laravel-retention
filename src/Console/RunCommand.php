<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Execution\ModelDiscovery;
use ItsJustVita\Retention\Execution\ModelRunResult;
use ItsJustVita\Retention\Execution\RetentionRunner;
use ItsJustVita\Retention\Execution\RunReport;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'retention:run')]
final class RunCommand extends Command
{
    protected $signature = 'retention:run
        {--model=* : Only process these models (class name or class basename)}
        {--dry-run : Show what is due without changing anything}
        {--chunk= : Records per chunk and transaction (default: retention.chunk_size)}';

    protected $description = 'Delete or anonymise every record whose retention period has expired';

    public function handle(RetentionRunner $runner, ModelDiscovery $discovery): int
    {
        try {
            $names = $this->listOption('model');
            $report = $runner->run(
                models: $names === [] ? null : $discovery->resolve($names),
                dryRun: (bool) $this->option('dry-run'),
                chunkSize: $this->chunkSize(),
            );
        } catch (InvalidRetentionPolicy $e) {
            $this->components->error('Retention policies are invalid; nothing was changed.');
            foreach ($e->violations as $violation) {
                $this->line("  - {$violation}");
            }

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $report->dryRun ? $this->renderPreview($report) : $this->renderRun($report);

        return self::SUCCESS;
    }

    private function renderRun(RunReport $report): void
    {
        $this->table(
            ['Category', 'Model', 'Deleted', 'Anonymised', 'Blocked', 'On hold', 'Skipped'],
            array_map(fn (ModelRunResult $result): array => [
                $result->category,
                $result->model,
                (string) $result->deleted,
                (string) $result->anonymized,
                (string) $result->blocked,
                (string) $result->onHold,
                (string) ($result->skipped + $result->cancelled),
            ], $report->results),
        );

        if ($report->purgedHolds > 0) {
            $this->components->info("Purged {$report->purgedHolds} released or expired legal hold(s).");
        }

        if ($report->purgedLogEntries > 0) {
            $this->components->info("Purged {$report->purgedLogEntries} log entry/entries older than the log retention period.");
        }

        if ($report->total('skipped') > 0) {
            $this->components->warn($report->total('skipped').' record(s) were selected as due but failed the per-record check and were left untouched. Please report this as a bug.');
        }

        if ($report->total('cancelled') > 0) {
            $this->components->warn($report->total('cancelled').' deletion(s) were cancelled by a model event listener.');
        }
    }

    private function renderPreview(RunReport $report): void
    {
        $this->components->info('Dry run: nothing was changed. Records shown as blocked wait for their blockedBy() relations.');

        $this->table(
            ['Category', 'Model', 'Due', 'Blocked', 'On hold'],
            array_map(fn (ModelRunResult $result): array => [
                $result->category,
                $result->model,
                (string) $result->due,
                (string) $result->blocked,
                (string) $result->onHold,
            ], $report->results),
        );
    }

    private function chunkSize(): ?int
    {
        $value = $this->option('chunk');

        if ($value === null) {
            return null;
        }

        $size = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($size === false) {
            throw new InvalidArgumentException('--chunk must be a positive integer.');
        }

        return $size;
    }

    /**
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $value = $this->option($name);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
