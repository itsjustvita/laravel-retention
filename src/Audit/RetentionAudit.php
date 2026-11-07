<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Audit;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Execution\ModelDiscovery;
use ItsJustVita\Retention\Log\DeletionLog;
use ItsJustVita\Retention\Models\RetentionHold;
use ItsJustVita\Retention\Models\RetentionLog;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Policies\PolicyValidator;
use ItsJustVita\Retention\Policies\TablePolicies;
use ItsJustVita\Retention\Rules\RuleCatalogue;
use ItsJustVita\Retention\Support\RelationResolver;
use Throwable;

/**
 * Checks that nothing ships without a deletion rule: every model and every
 * table needs a policy or a justified exclusion, every policy must be valid,
 * and foreign keys must not work against the policies.
 */
final readonly class RetentionAudit
{
    public function __construct(
        private ModelDiscovery $discovery,
        private PolicyRegistry $policies,
        private PolicyValidator $validator,
        private TablePolicies $tablePolicies,
        private Exclusions $exclusions,
        private DeletionLog $log,
        private RuleCatalogue $rules,
        private Repository $config,
        private DatabaseManager $db,
    ) {}

    public function run(): AuditReport
    {
        $models = $this->discovery->models();

        return new AuditReport([
            ...$this->exclusionFindings(),
            ...$this->modelFindings($models),
            ...$this->tableFindings($models),
            ...$this->policyFindings($models),
            ...$this->foreignKeyFindings($models),
            ...$this->categoryFindings($models),
            ...$this->logFindings(),
        ]);
    }

    /**
     * @return list<AuditFinding>
     */
    private function exclusionFindings(): array
    {
        return array_map(
            fn (string $violation): AuditFinding => new AuditFinding('exclusions', AuditSeverity::Error, $violation),
            $this->exclusions->violations(),
        );
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return list<AuditFinding>
     */
    private function modelFindings(array $models): array
    {
        $findings = [];
        $excluded = $this->exclusions->models();

        foreach ($models as $model) {
            $isExcluded = isset($excluded[$model]);

            if ($this->policies->has($model)) {
                if ($isExcluded) {
                    $findings[] = new AuditFinding('models', AuditSeverity::Warning, "[{$model}] is excluded but also has a retention policy.");
                }
            } elseif (! $isExcluded) {
                $findings[] = new AuditFinding('models', AuditSeverity::Error, "[{$model}] has no retention policy and no exclusion.");
            }
        }

        foreach (array_keys($excluded) as $model) {
            if (! in_array($model, $models, true)) {
                $findings[] = new AuditFinding('models', AuditSeverity::Warning, "[{$model}] is excluded but is not a discovered model.");
            }
        }

        return $findings;
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return list<AuditFinding>
     */
    private function tableFindings(array $models): array
    {
        $covered = $this->coveredTables($models);
        $excluded = $this->exclusions->tables();
        $existing = [];
        $findings = [];

        foreach ($this->connections() as $connection) {
            foreach ($this->tablesOn($connection) as $table) {
                $existing[$table] = true;

                if (! isset($covered[$connection->getName().'|'.$table]) && ! isset($excluded[$table])) {
                    $findings[] = new AuditFinding('tables', AuditSeverity::Error, "table [{$table}] has no model with a retention policy, no table policy and no exclusion.");
                }
            }
        }

        foreach (array_keys($excluded) as $table) {
            if (! isset($existing[$table])) {
                $findings[] = new AuditFinding('tables', AuditSeverity::Warning, "table [{$table}] is excluded but does not exist.");
            }
        }

        return $findings;
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return array<string, true> by "connection|table"
     */
    private function coveredTables(array $models): array
    {
        $covered = [];
        $excludedModels = $this->exclusions->models();

        foreach ($models as $model) {
            if ($this->policies->has($model) || isset($excludedModels[$model])) {
                $instance = new $model;
                $covered[$instance->getConnection()->getName().'|'.$instance->getTable()] = true;
            }
        }

        foreach ([new RetentionHold, new RetentionLog] as $packageModel) {
            $covered[$packageModel->getConnection()->getName().'|'.$packageModel->getTable()] = true;
        }

        foreach ($this->tablePolicies->definitions() as $table => $definition) {
            $connection = is_array($definition) && is_string($definition['connection'] ?? null) ? $definition['connection'] : null;
            $covered[($connection ?? $this->db->getDefaultConnection()).'|'.$table] = true;
        }

        return $covered;
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return list<AuditFinding>
     */
    private function policyFindings(array $models): array
    {
        $messages = [...$this->validator->environmentViolations(), ...$this->validator->tablePolicyViolations()];

        foreach ($models as $model) {
            if ($this->policies->has($model)) {
                foreach ($this->validator->violations($model) as $violation) {
                    $messages[] = "[{$model}]: {$violation}";
                }
            }
        }

        return array_map(fn (string $message): AuditFinding => new AuditFinding('policies', AuditSeverity::Error, $message), $messages);
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return list<AuditFinding>
     */
    private function foreignKeyFindings(array $models): array
    {
        [$deleting, $retained] = $this->tablesByPolicy($models);
        $findings = [];

        foreach ($this->connections() as $connection) {
            $schema = $connection->getSchemaBuilder();
            $prefix = $connection->getTablePrefix();

            foreach ($this->tablesOn($connection) as $table) {
                foreach ($schema->getForeignKeys($table) as $foreignKey) {
                    $parent = substr($foreignKey['foreign_table'], strlen($prefix));

                    if ($parent === $table || ! isset($deleting[$parent])) {
                        continue;
                    }

                    $finding = $this->foreignKeyFinding($table, $parent, $foreignKey['columns'], $foreignKey['on_delete'] ?? 'no action', $deleting[$parent], $retained[$table] ?? null);

                    if ($finding !== null) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, true>  $blockedBy  tables covered by the parent's blockedBy()
     */
    private function foreignKeyFinding(string $table, string $parent, array $columns, string $onDelete, array $blockedBy, ?string $retainedByRule): ?AuditFinding
    {
        if ($onDelete === 'cascade') {
            return $retainedByRule === null ? null : new AuditFinding(
                'foreign_keys',
                AuditSeverity::Error,
                "deleting from [{$parent}] cascades into [{$table}], whose rule [{$retainedByRule}] requires keeping the rows. Use a restricting foreign key and blockedBy().",
            );
        }

        if ($onDelete === 'set null' || $onDelete === 'set default' || isset($blockedBy[$table])) {
            return null;
        }

        $column = $table.'.'.implode(',', $columns);

        return new AuditFinding(
            'foreign_keys',
            AuditSeverity::Warning,
            "[{$column}] references [{$parent}], whose policy deletes rows, but blockedBy() does not cover [{$table}]; the deletion would fail on the constraint.",
        );
    }

    /**
     * The tables whose rows get deleted (with the tables their blockedBy()
     * covers), and the tables that must be kept (with the blocking rule).
     *
     * @param  list<class-string<Model>>  $models
     * @return array{array<string, array<string, true>>, array<string, string>}
     */
    private function tablesByPolicy(array $models): array
    {
        $deleting = [];
        $retained = [];

        foreach ($models as $model) {
            if (! $this->policies->has($model)) {
                continue;
            }

            try {
                $policy = $this->policies->for($model);
            } catch (Throwable) {
                continue; // Reported by policyFindings().
            }

            $instance = new $model;
            $table = $instance->getTable();

            if ($policy->finalAction === Action::Delete) {
                $deleting[$table] ??= [];

                foreach ($policy->blockingRelations as $relation) {
                    if (RelationResolver::violation($instance, $relation) === null) {
                        $deleting[$table][RelationResolver::resolve($instance, $relation)->getRelated()->getTable()] = true;
                    }
                }
            }

            if ($policy->ruleKey !== null && $this->rules->has($policy->ruleKey) && $this->rules->get($policy->ruleKey)->blocksErasure) {
                $retained[$table] = $policy->ruleKey;
            }
        }

        foreach ($this->validTablePolicies() as $table => $ruleKey) {
            $deleting[$table] ??= [];

            if ($this->rules->has($ruleKey) && $this->rules->get($ruleKey)->blocksErasure) {
                $retained[$table] = $ruleKey;
            }
        }

        return [$deleting, $retained];
    }

    /**
     * @return array<string, string> rule key by table
     */
    private function validTablePolicies(): array
    {
        $tables = [];

        foreach ($this->tablePolicies->definitions() as $table => $definition) {
            if (is_array($definition) && is_string($definition['rule'] ?? null)) {
                $tables[$table] = $definition['rule'];
            }
        }

        return $tables;
    }

    /**
     * A data category may span several models, but needs one label.
     *
     * @param  list<class-string<Model>>  $models
     * @return list<AuditFinding>
     */
    private function categoryFindings(array $models): array
    {
        $labels = [];

        foreach ($models as $model) {
            if (! $this->policies->has($model)) {
                continue;
            }

            try {
                $policy = $this->policies->for($model);
            } catch (Throwable) {
                continue; // Reported by policyFindings().
            }

            $labels[$policy->category][$policy->label] = true;
        }

        $findings = [];

        foreach ($labels as $category => $distinct) {
            if (count($distinct) > 1) {
                $names = array_map(strval(...), array_keys($distinct));
                sort($names);

                $findings[] = new AuditFinding('policies', AuditSeverity::Warning, "category [{$category}] has different labels: [".implode('], [', $names).'].');
            }
        }

        return $findings;
    }

    /**
     * @return list<AuditFinding>
     */
    private function logFindings(): array
    {
        return $this->log->enabled() && $this->log->usesApplicationKey()
            ? [new AuditFinding('log', AuditSeverity::Warning, 'The deletion log uses APP_KEY to pseudonymise record IDs; rotating APP_KEY would make older entries unverifiable. Set RETENTION_LOG_KEY.')]
            : [];
    }

    /**
     * @return list<Connection>
     */
    private function connections(): array
    {
        $names = $this->config->get('retention.audit.connections') ?? [null];

        return array_map(
            fn (mixed $name): Connection => $this->db->connection(is_string($name) ? $name : null),
            is_array($names) ? array_values($names) : [null],
        );
    }

    /**
     * The table names of the connection's current schema, without prefix.
     *
     * @return list<string>
     */
    private function tablesOn(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $prefix = $connection->getTablePrefix();

        return array_map(
            fn (array $table): string => substr($table['name'], strlen($prefix)),
            $schema->getTables($schema->getCurrentSchemaName()),
        );
    }
}
