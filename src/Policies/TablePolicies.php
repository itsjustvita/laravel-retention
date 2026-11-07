<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Policies;

use Illuminate\Contracts\Config\Repository;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;

/**
 * The table policies from config('retention.tables').
 */
final readonly class TablePolicies
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array<string, TablePolicy> keyed by table name
     *
     * @throws InvalidRetentionPolicy
     */
    public function all(): array
    {
        $policies = [];

        foreach ($this->definitions() as $table => $definition) {
            if (! is_array($definition)) {
                throw new InvalidRetentionPolicy("table {$table}", ['the definition must be an array.']);
            }

            $policies[$table] = TablePolicy::fromArray($table, $definition);
        }

        ksort($policies);

        return $policies;
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(): array
    {
        $definitions = $this->config->get('retention.tables') ?? [];
        $result = [];

        foreach (is_array($definitions) ? $definitions : [] as $table => $definition) {
            $result[(string) $table] = $definition;
        }

        return $result;
    }
}
