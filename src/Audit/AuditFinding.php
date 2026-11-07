<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Audit;

/**
 * One problem found by retention:audit.
 */
final readonly class AuditFinding
{
    /**
     * @param  'models'|'tables'|'policies'|'foreign_keys'|'exclusions'|'log'  $section
     */
    public function __construct(
        public string $section,
        public AuditSeverity $severity,
        public string $message,
    ) {}
}
