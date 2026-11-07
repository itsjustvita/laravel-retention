<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Audit;

/**
 * Result of retention:audit.
 */
final readonly class AuditReport
{
    /**
     * @param  list<AuditFinding>  $findings
     */
    public function __construct(
        public array $findings,
    ) {}

    /**
     * @return list<AuditFinding>
     */
    public function errors(): array
    {
        return $this->withSeverity(AuditSeverity::Error);
    }

    /**
     * @return list<AuditFinding>
     */
    public function warnings(): array
    {
        return $this->withSeverity(AuditSeverity::Warning);
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /**
     * @return list<AuditFinding>
     */
    private function withSeverity(AuditSeverity $severity): array
    {
        return array_values(array_filter($this->findings, fn (AuditFinding $finding): bool => $finding->severity === $severity));
    }
}
