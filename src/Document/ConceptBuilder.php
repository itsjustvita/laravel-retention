<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Document;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use ItsJustVita\Retention\Audit\Exclusions;
use ItsJustVita\Retention\Calculation\RetentionCalculator;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Execution\ModelDiscovery;
use ItsJustVita\Retention\Log\DeletionLog;
use ItsJustVita\Retention\Policies\PolicyRegistry;
use ItsJustVita\Retention\Policies\TablePolicies;
use ItsJustVita\Retention\Rules\Period;
use ItsJustVita\Retention\Rules\RetentionRule;
use ItsJustVita\Retention\Rules\RuleCatalogue;

/**
 * Collects the deletion concept from the very same declarations the package
 * enforces: model policies, table policies, rules and exclusions.
 */
final readonly class ConceptBuilder
{
    public function __construct(
        private ModelDiscovery $discovery,
        private PolicyRegistry $policies,
        private TablePolicies $tablePolicies,
        private RuleCatalogue $rules,
        private Exclusions $exclusions,
        private DeletionLog $log,
        private GitInfo $git,
        private RetentionCalculator $calculator,
        private Repository $config,
    ) {}

    /**
     * @param  CarbonImmutable|null  $asOf  overrides the date of the last Git commit
     */
    public function build(?CarbonImmutable $asOf = null): DeletionConcept
    {
        $commit = $asOf === null ? $this->git->lastCommit() : null;
        $date = $asOf ?? $commit['date'] ?? CarbonImmutable::now();

        return new DeletionConcept(
            organisation: $this->documentString('organisation'),
            controller: $this->documentString('controller'),
            dataProtectionOfficer: $this->documentString('data_protection_officer'),
            version: $this->documentString('version'),
            asOf: $asOf ?? $date->setTimezone($this->calculator->timezone()),
            commit: $commit === null ? null : substr($commit['hash'], 0, 12),
            entries: $this->entries(),
            excludedModels: $this->exclusions->models(),
            excludedTables: $this->exclusions->tables(),
            timezone: $this->calculator->timezone(),
            logEnabled: $this->log->enabled(),
            logRetention: $this->log->retention(),
            holdsRetention: $this->holdsRetention(),
            purpose: $this->documentText('purpose'),
            systemsNotCovered: $this->documentText('systems_not_covered'),
        );
    }

    /**
     * @return list<ConceptEntry>
     */
    private function entries(): array
    {
        $groups = [];

        foreach ($this->discovery->retainedModels() as $model) {
            $policy = $this->policies->for($model);
            $instance = new $model;

            $this->add(
                $groups,
                category: $policy->category,
                label: $policy->label,
                description: $policy->description,
                target: $model.' ('.$instance->getTable().')',
                rule: $this->rules->get((string) $policy->ruleKey),
                startEvent: $policy->startEvent ?? StartEvent::Collection,
                action: $policy->finalAction ?? Action::Delete,
                responsible: (string) $policy->responsibleUnit,
                blockedBy: $policy->blockingRelations,
                inheritsHoldsFrom: $policy->holdRelations,
            );
        }

        foreach ($this->tablePolicies->all() as $policy) {
            $this->add(
                $groups,
                category: $policy->category,
                label: $policy->label,
                description: $policy->description,
                target: $policy->table,
                rule: $this->rules->get($policy->ruleKey),
                startEvent: $policy->startEvent,
                action: Action::Delete,
                responsible: $policy->responsibleUnit,
                blockedBy: [],
                inheritsHoldsFrom: [],
            );
        }

        ksort($groups);

        return array_map(fn (array $group): ConceptEntry => new ConceptEntry(
            category: $group['category'],
            label: $group['label'],
            description: $group['description'],
            targets: self::sorted($group['targets']),
            rule: $group['rule'],
            startEvent: $group['startEvent'],
            action: $group['action'],
            responsible: $group['responsible'],
            blockedBy: self::sorted($group['blockedBy']),
            inheritsHoldsFrom: self::sorted($group['inheritsHoldsFrom']),
        ), array_values($groups));
    }

    /**
     * @param  array<string, array{category: string, label: string, description: string|null, targets: list<string>, rule: RetentionRule, startEvent: StartEvent, action: Action, responsible: string, blockedBy: list<string>, inheritsHoldsFrom: list<string>}>  $groups
     * @param  list<string>  $blockedBy
     * @param  list<string>  $inheritsHoldsFrom
     */
    private function add(array &$groups, string $category, string $label, ?string $description, string $target, RetentionRule $rule, StartEvent $startEvent, Action $action, string $responsible, array $blockedBy, array $inheritsHoldsFrom): void
    {
        $key = implode('|', [$category, $rule->key, $startEvent->value, $action->value, $responsible]);

        $groups[$key] ??= [
            'category' => $category,
            'label' => $label,
            'description' => null,
            'targets' => [],
            'rule' => $rule,
            'startEvent' => $startEvent,
            'action' => $action,
            'responsible' => $responsible,
            'blockedBy' => [],
            'inheritsHoldsFrom' => [],
        ];

        $groups[$key]['description'] ??= $description;
        $groups[$key]['targets'][] = $target;
        $groups[$key]['blockedBy'] = [...$groups[$key]['blockedBy'], ...$blockedBy];
        $groups[$key]['inheritsHoldsFrom'] = [...$groups[$key]['inheritsHoldsFrom'], ...$inheritsHoldsFrom];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private function holdsRetention(): Period
    {
        $value = $this->config->get('retention.holds.retention', 'P3Y');

        if (! is_string($value)) {
            throw new InvalidArgumentException('[retention.holds.retention] must be an ISO 8601 duration such as P3Y.');
        }

        return Period::parse($value);
    }

    private function documentString(string $key): ?string
    {
        $value = $this->config->get("retention.document.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return string|array<mixed>|null
     */
    private function documentText(string $key): string|array|null
    {
        $value = $this->config->get("retention.document.{$key}");

        return is_string($value) || is_array($value) ? $value : null;
    }
}
