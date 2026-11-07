<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Execution;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use ItsJustVita\Retention\Enums\Trigger;
use ItsJustVita\Retention\Events\TableRowsDeleted;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Policies\TablePolicy;
use ItsJustVita\Retention\Rules\RuleCatalogue;

/**
 * Selects and deletes the due rows of a table with a table policy.
 */
final readonly class DueTableRows
{
    public function __construct(
        private RuleCatalogue $rules,
        private RetentionCalculator $calculator,
        private StartColumn $startColumn,
        private DatabaseManager $db,
        private Dispatcher $events,
    ) {}

    public function query(TablePolicy $policy, CarbonInterface $now): Builder
    {
        $connection = $this->db->connection($policy->connection);

        $type = $this->startColumn->typeOn($connection, $policy->table, $policy->startColumn)
            ?? throw new InvalidRetentionPolicy("table {$policy->table}", ["start column [{$policy->startColumn}] is not a date, datetime/timestamp or integer column."]);

        [$operator, $value] = $this->startColumn->condition(
            $type,
            $this->calculator->dueConstraint($this->rules->get($policy->ruleKey), $now),
            $connection->getQueryGrammar()->getDateFormat(),
        );

        return $connection->table($policy->table)
            ->whereNotNull($policy->startColumn)
            ->where($policy->startColumn, $operator, $value);
    }

    /**
     * Deletes up to `$limit` due rows in one transaction, together with the
     * event that writes their log entry.
     *
     * @return int number of deleted rows
     */
    public function deleteBatch(TablePolicy $policy, CarbonInterface $now, int $limit): int
    {
        return $this->db->connection($policy->connection)->transaction(function () use ($policy, $now, $limit): int {
            $deleted = $this->query($policy, $now)->limit($limit)->delete();

            if ($deleted > 0) {
                $this->events->dispatch(new TableRowsDeleted($policy, $deleted, Trigger::Schedule));
            }

            return $deleted;
        });
    }
}
