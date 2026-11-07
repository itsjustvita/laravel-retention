<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Exceptions;

use InvalidArgumentException;

final class InvalidRetentionPolicy extends InvalidArgumentException implements RetentionException
{
    /**
     * @param  string  $subject  Model class, or category key while the policy is being built.
     * @param  non-empty-list<string>  $violations
     */
    public function __construct(
        public readonly string $subject,
        public readonly array $violations,
    ) {
        parent::__construct(count($violations) === 1
            ? "Invalid retention policy for [{$subject}]: {$violations[0]}"
            : "Invalid retention policy for [{$subject}]:\n- ".implode("\n- ", $violations));
    }

    /**
     * @param  list<\BackedEnum>  $allowed
     */
    public static function unknownValue(string $subject, string $what, string $value, array $allowed): self
    {
        $values = implode(', ', array_map(fn (\BackedEnum $case): string => (string) $case->value, $allowed));

        return new self($subject, ["unknown {$what} [{$value}]; expected one of [{$values}]."]);
    }
}
