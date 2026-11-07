<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Rules;

use ItsJustVita\Retention\Exceptions\InvalidRuleDefinition;
use ItsJustVita\Retention\Exceptions\UnknownRule;

/**
 * All retention rules available to policies: the shipped catalogue merged
 * with the overrides and additions from config('retention.rules').
 */
final class RuleCatalogue
{
    /** @var array<string, RetentionRule> */
    private readonly array $rules;

    /**
     * @param  array<string, RetentionRule>  $rules
     */
    public function __construct(array $rules)
    {
        ksort($rules);

        $this->rules = $rules;
    }

    /**
     * Merges overrides into the shipped definitions key by key: an override
     * only needs the keys it changes, and `null` removes an optional key.
     *
     * @param  array<string, array<string, mixed>>  $shipped
     * @param  array<mixed>  $overrides
     */
    public static function fromDefinitions(array $shipped, array $overrides = []): self
    {
        $definitions = $shipped;

        foreach ($overrides as $key => $override) {
            $key = (string) $key;

            if (! is_array($override)) {
                throw InvalidRuleDefinition::for($key, 'the definition must be an array.');
            }

            $definitions[$key] = array_replace($definitions[$key] ?? [], $override);
        }

        $rules = [];

        foreach ($definitions as $key => $definition) {
            $rules[$key] = RetentionRule::fromArray($key, $definition);
        }

        return new self($rules);
    }

    public function get(string $key): RetentionRule
    {
        return $this->rules[$key] ?? throw UnknownRule::key($key);
    }

    public function has(string $key): bool
    {
        return isset($this->rules[$key]);
    }

    /**
     * @return array<string, RetentionRule>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
