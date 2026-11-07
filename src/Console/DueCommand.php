<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ItsJustVita\Retention\Concerns\Restrictable;
use ItsJustVita\Retention\Database\RetentionColumns;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Execution\DueRecords;
use ItsJustVita\Retention\Execution\DueTableRows;
use ItsJustVita\Retention\Execution\ModelDiscovery;
use ItsJustVita\Retention\Execution\RetentionRunner;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Policies\TablePolicies;
use ItsJustVita\Retention\Policies\TablePolicy;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'retention:due')]
final class DueCommand extends Command
{
    protected $signature = 'retention:due
        {--model=* : Only show these models (class name or class basename)}
        {--days=30 : Forecast horizon in days}';

    protected $description = 'Show how many records are due for deletion or anonymisation';

    public function handle(ModelDiscovery $discovery, RetentionRunner $runner, DueRecords $dueRecords, DueTableRows $dueTableRows, TablePolicies $tablePolicies, PolicyRegistry $policies): int
    {
        try {
            $days = $this->days();
            $names = $this->listOption('model');
            $models = $names === [] ? $discovery->retainedModels() : $discovery->resolve($names);
            $runner->validate($models, includeTables: $names === []);
            $tables = $names === [] ? $tablePolicies->all() : [];
        } catch (InvalidRetentionPolicy $e) {
            $this->components->error('Retention policies are invalid.');
            foreach ($e->violations as $violation) {
                $this->line("  - {$violation}");
            }

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $horizon = $now->addDays($days);

        $tableRows = array_map(function (TablePolicy $policy) use ($dueTableRows, $now, $horizon): array {
            $dueNow = $dueTableRows->query($policy, $now)->count();

            return [
                $policy->category,
                $policy->table,
                (string) $dueNow,
                '0',
                '0',
                (string) ($dueTableRows->query($policy, $horizon)->count() - $dueNow),
                '–',
            ];
        }, array_values($tables));

        $this->table(
            ['Category', 'Model', 'Due now', 'Blocked', 'On hold', "Due within {$days} days", 'Restricted'],
            [...array_map(function (string $model) use ($runner, $dueRecords, $policies, $now, $horizon): array {
                $remaining = $runner->remaining($model, $now);
                $expired = $remaining['due'] + $remaining['blocked'] + $remaining['onHold'];
                $expiredByHorizon = $dueRecords->query($model, $horizon, excludeBlocked: false, excludeHeld: false)->count();

                return [
                    $policies->for($model)->category,
                    $model,
                    (string) $remaining['due'],
                    (string) $remaining['blocked'],
                    (string) $remaining['onHold'],
                    (string) ($expiredByHorizon - $expired),
                    $this->restrictedCount($model),
                ];
            }, $models), ...$tableRows],
        );

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function restrictedCount(string $model): string
    {
        if (! in_array(Restrictable::class, class_uses_recursive($model), true)) {
            return '–';
        }

        $instance = new $model;

        return (string) $instance->newQueryWithoutScopes()
            ->whereNotNull($instance->qualifyColumn(RetentionColumns::RESTRICTED_AT))
            ->count();
    }

    private function days(): int
    {
        $value = $this->option('days');

        $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($days === false) {
            throw new InvalidArgumentException('--days must be a non-negative integer.');
        }

        return $days;
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
