<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Attributes;

use Attribute;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\ErasureBeforeStart;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Exceptions\MissingRetentionPolicy;
use ItsJustVita\Retention\RetentionPolicy;
use ReflectionClass;

/**
 * Declares a model's retention policy as an attribute. Maps to the same
 * RetentionPolicy as the retentionPolicy() method; use the method when the
 * anonymizer needs a closure.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Retention
{
    /**
     * @param  string|null  $anonymizer  Invokable class name or name of a method on the model.
     * @param  list<string>  $blockedBy
     * @param  list<string>  $inheritsHoldsFrom
     */
    public function __construct(
        public string $category,
        public string $label,
        public string $rule,
        public string $startsAt,
        public StartEvent|string $startEvent,
        public Action|string $action,
        public string $responsible,
        public ?string $anonymizer = null,
        public array $blockedBy = [],
        public array $inheritsHoldsFrom = [],
        public ErasureBeforeStart|string $onErasureBeforeStart = ErasureBeforeStart::Skip,
        public ?string $description = null,
    ) {}

    /**
     * @param  class-string  $class
     */
    public static function policyFor(string $class): RetentionPolicy
    {
        $attributes = (new ReflectionClass($class))->getAttributes(self::class);

        if ($attributes === []) {
            throw MissingRetentionPolicy::for($class);
        }

        return $attributes[0]->newInstance()->toPolicy();
    }

    public function toPolicy(): RetentionPolicy
    {
        $policy = RetentionPolicy::category($this->category, $this->label)
            ->rule($this->rule)
            ->startsAt($this->startsAt, $this->startEvent)
            ->action($this->action)
            ->responsible($this->responsible)
            ->blockedBy($this->blockedBy)
            ->inheritsHoldsFrom($this->inheritsHoldsFrom)
            ->onErasureBeforeStart($this->onErasureBeforeStart);

        if ($this->anonymizer !== null) {
            $policy->anonymizeUsing($this->anonymizer);
        }

        if ($this->description !== null) {
            $policy->description($this->description);
        }

        return $policy;
    }
}
