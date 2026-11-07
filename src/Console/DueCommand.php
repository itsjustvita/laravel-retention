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
use ItsJustVita\Retention\Execution\ModelDiscovery;
use ItsJustVita\Retention\Execution\RetentionRunner;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'retention:due')]
final class DueCommand extends Command
{
    protected $signature = 'retention:due
        {--model=* : Only show these models (class name or class basename)}
        {--days=30 : Forecast horizon in days}';

    protected $description = 'Show how many records are due for deletion or anonymisation';

    public function handle(ModelDiscovery $discovery, RetentionRunner $runner, DueRecords $dueRecords, PolicyRegistry $policies): int
    {
        try {
            $days = $this->days();
            $names = $this->listOption('model');
            $models = $names === [] ? $discovery->retainedModels() : $discovery->resolve($names);
            $runner->validate($models);
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

        $this->table(
            ['Category', 'Model', 'Due now', 'Blocked', "Due within {$days} days", 'Restricted'],
            array_map(function (string $model) use ($dueRecords, $policies, $now, $horizon): array {
                $dueNow = $dueRecords->query($model, $now)->count();
                $dueNowIncludingBlocked = $dueRecords->query($model, $now, excludeBlocked: false)->count();

                return [
                    $policies->for($model)->category,
                    $model,
                    (string) $dueNow,
                    (string) ($dueNowIncludingBlocked - $dueNow),
                    (string) ($dueRecords->query($model, $horizon, excludeBlocked: false)->count() - $dueNowIncludingBlocked),
                    $this->restrictedCount($model),
                ];
            }, $models),
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
