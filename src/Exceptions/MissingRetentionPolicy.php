<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Exceptions;

use InvalidArgumentException;

final class MissingRetentionPolicy extends InvalidArgumentException implements RetentionException
{
    public static function for(string $model): self
    {
        return new self("[{$model}] has no retention policy. Use the HasRetention trait and define retentionPolicy() or add a #[Retention] attribute.");
    }
}
