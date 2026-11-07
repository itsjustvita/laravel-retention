<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Exceptions;

use InvalidArgumentException;

final class UnknownRule extends InvalidArgumentException implements RetentionException
{
    public static function key(string $key): self
    {
        return new self("Unknown retention rule [{$key}]. Define it under [rules] in config/retention.php.");
    }
}
