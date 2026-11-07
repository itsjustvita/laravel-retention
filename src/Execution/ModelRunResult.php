<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Execution;

use Illuminate\Database\Eloquent\Model;

/**
 * What retention:run did, or would do, for one model.
 */
final readonly class ModelRunResult
{
    /**
     * @param  class-string<Model>  $model
     * @param  int  $due  Records due and not blocked when the run started (dry runs only).
     * @param  int  $blocked  Records still due but blocked by blockedBy() relations.
     * @param  int  $cancelled  Deletions cancelled by a model event listener.
     * @param  int  $skipped  Records selected as due that failed the per-record check.
     */
    public function __construct(
        public string $model,
        public string $category,
        public int $due = 0,
        public int $deleted = 0,
        public int $anonymized = 0,
        public int $blocked = 0,
        public int $cancelled = 0,
        public int $skipped = 0,
    ) {}
}
