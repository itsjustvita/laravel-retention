<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Audit;

enum AuditSeverity
{
    /** Makes retention:audit exit with a non-zero code. */
    case Error;

    /** Reported; only fails with --strict. */
    case Warning;
}
