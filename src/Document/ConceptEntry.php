<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Document;

use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Rules\RetentionRule;

/**
 * One line of the deletion concept: a data category with one rule, start
 * event, final action and responsible unit, and the models and tables it
 * applies to. A category with different rules has several entries.
 */
final readonly class ConceptEntry
{
    /**
     * @param  list<string>  $targets  models and tables
     * @param  list<string>  $blockedBy
     * @param  list<string>  $inheritsHoldsFrom
     */
    public function __construct(
        public string $category,
        public string $label,
        public ?string $description,
        public array $targets,
        public RetentionRule $rule,
        public StartEvent $startEvent,
        public Action $action,
        public string $responsible,
        public array $blockedBy,
        public array $inheritsHoldsFrom,
    ) {}
}
