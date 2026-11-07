<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Log;

use Illuminate\Contracts\Container\Container;
use ItsJustVita\Retention\Events\RecordAnonymized;
use ItsJustVita\Retention\Events\RecordDeleted;
use ItsJustVita\Retention\Events\RecordRestricted;
use ItsJustVita\Retention\Events\TableRowsDeleted;
use ItsJustVita\Retention\Models\RetentionLog;
use LogicException;

/**
 * Writes a deletion log entry for every deletion, anonymisation and
 * restriction, and one aggregated entry per batch of rows deleted through a
 * table policy. Runs synchronously, so the entry shares the transaction of
 * the action it records.
 */
final readonly class WriteRetentionLog
{
    public function __construct(
        private DeletionLog $log,
        private Container $container,
    ) {}

    public function handle(RecordDeleted|RecordAnonymized|RecordRestricted|TableRowsDeleted $event): void
    {
        if (! $this->log->enabled()) {
            return;
        }

        if ($event instanceof TableRowsDeleted) {
            RetentionLog::query()->create([
                'model' => $event->policy->table,
                'record_count' => $event->count,
                'category' => $event->policy->category,
                'rule' => $event->policy->ruleKey,
                'action' => 'deleted',
                'trigger' => $event->trigger->value,
            ]);

            return;
        }

        $id = $event->model->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw new LogicException('Cannot log a record of ['.$event->model::class.'] without a scalar primary key.');
        }

        $hash = $this->container->make(LogHasher::class)->hash($event->model::class, $id);

        RetentionLog::query()->create([
            'model' => $event->model::class,
            'record_hash' => $hash['hash'],
            'key_id' => $hash['key_id'],
            'category' => $event->policy->category,
            'rule' => (string) $event->policy->ruleKey,
            'action' => match (true) {
                $event instanceof RecordDeleted => 'deleted',
                $event instanceof RecordAnonymized => 'anonymized',
                $event instanceof RecordRestricted => 'restricted',
            },
            'trigger' => $event->trigger->value,
        ]);
    }
}
