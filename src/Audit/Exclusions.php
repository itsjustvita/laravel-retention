<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Audit;

use Illuminate\Contracts\Config\Repository;

/**
 * Models and tables deliberately without a retention policy, from
 * config('retention.exclusions'). Every exclusion needs a justification,
 * which ends up in the generated document.
 */
final readonly class Exclusions
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array<string, string> justification by model class
     */
    public function models(): array
    {
        return $this->valid('models');
    }

    /**
     * @return array<string, string> justification by table name
     */
    public function tables(): array
    {
        return $this->valid('tables');
    }

    /**
     * @return list<string>
     */
    public function violations(): array
    {
        $violations = [];

        foreach (['models', 'tables'] as $type) {
            foreach ($this->configured($type) as $name => $justification) {
                if (is_int($name)) {
                    $name = is_string($justification) ? $justification : (string) $name;
                    $justification = null;
                }

                if (! is_string($justification) || trim($justification) === '') {
                    $violations[] = "exclusion [{$name}] needs a justification; it ends up in the deletion concept.";
                }
            }
        }

        return $violations;
    }

    /**
     * @param  'models'|'tables'  $type
     * @return array<string, string>
     */
    private function valid(string $type): array
    {
        $valid = [];

        foreach ($this->configured($type) as $name => $justification) {
            if (is_string($name) && is_string($justification) && trim($justification) !== '') {
                $valid[$name] = $justification;
            }
        }

        ksort($valid);

        return $valid;
    }

    /**
     * @param  'models'|'tables'  $type
     * @return array<mixed>
     */
    private function configured(string $type): array
    {
        $configured = $this->config->get("retention.exclusions.{$type}") ?? [];

        return is_array($configured) ? $configured : [];
    }
}
