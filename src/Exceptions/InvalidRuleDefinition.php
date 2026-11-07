<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Exceptions;

use InvalidArgumentException;
use Throwable;

final class InvalidRuleDefinition extends InvalidArgumentException implements RetentionException
{
    public function __construct(
        public readonly string $ruleKey,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Retention rule [{$ruleKey}]: {$reason}", previous: $previous);
    }

    public static function for(string $ruleKey, string $reason, ?Throwable $previous = null): self
    {
        return new self($ruleKey, $reason, $previous);
    }
}
