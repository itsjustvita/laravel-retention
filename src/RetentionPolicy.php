<?php

declare(strict_types=1);

namespace ItsJustVita\Retention;

use Closure;
use ItsJustVita\Retention\Enums\Action;
use ItsJustVita\Retention\Enums\ErasureBeforeStart;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;

/**
 * The retention declaration of a model: data category, rule, start event,
 * final action and responsibility.
 *
 * Built fluently in a model's static retentionPolicy() method or from the
 * #[Retention] attribute. Completeness and consistency with the database are
 * checked by PolicyValidator, not here.
 */
final class RetentionPolicy
{
    public private(set) ?string $description = null;

    public private(set) ?string $ruleKey = null;

    public private(set) ?string $startColumn = null;

    public private(set) ?StartEvent $startEvent = null;

    public private(set) ?Action $finalAction = null;

    /** Closure, invokable class name, or name of a method on the model. */
    public private(set) Closure|string|null $anonymizer = null;

    public private(set) ?string $responsibleUnit = null;

    /** @var list<string> */
    public private(set) array $blockingRelations = [];

    /** @var list<string> */
    public private(set) array $holdRelations = [];

    public private(set) ErasureBeforeStart $erasureBeforeStart = ErasureBeforeStart::Skip;

    private function __construct(
        public readonly string $category,
        public readonly string $label,
    ) {}

    /**
     * Starts a policy for the given data category (DIN 66398 "Datenart").
     */
    public static function category(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Key of the retention rule from the rule catalogue.
     */
    public function rule(string $key): self
    {
        $this->ruleKey = $key;

        return $this;
    }

    /**
     * The column holding the date of the start event. While it is null, no
     * period is running.
     */
    public function startsAt(string $column, StartEvent|string $event): self
    {
        $this->startColumn = $column;
        $this->startEvent = $event instanceof StartEvent
            ? $event
            : StartEvent::tryFrom($event) ?? throw InvalidRetentionPolicy::unknownValue($this->category, 'start event', $event, StartEvent::cases());

        return $this;
    }

    /**
     * What happens when the period expires: delete or anonymize.
     */
    public function action(Action|string $action): self
    {
        $this->finalAction = $action instanceof Action
            ? $action
            : Action::tryFrom($action) ?? throw InvalidRetentionPolicy::unknownValue($this->category, 'action', $action, Action::cases());

        return $this;
    }

    /**
     * How to anonymize a record: a closure receiving the model, the class name
     * of an invokable class, or the name of a method on the model.
     */
    public function anonymizeUsing(Closure|string $anonymizer): self
    {
        $this->anonymizer = $anonymizer;

        return $this;
    }

    /**
     * The organisational unit responsible for this data category.
     */
    public function responsible(string $unit): self
    {
        $this->responsibleUnit = $unit;

        return $this;
    }

    /**
     * Relations that must be empty before a record may be deleted, e.g. the
     * invoices of an order that still have to be kept.
     *
     * @param  list<string>|string  $relations
     */
    public function blockedBy(array|string $relations): self
    {
        $this->blockingRelations = self::merge($this->blockingRelations, $relations);

        return $this;
    }

    /**
     * Relations whose legal holds also protect this record, e.g. the customer
     * of an invoice.
     *
     * @param  list<string>|string  $relations
     */
    public function inheritsHoldsFrom(array|string $relations): self
    {
        $this->holdRelations = self::merge($this->holdRelations, $relations);

        return $this;
    }

    /**
     * How an erasure request treats a record whose start event has not
     * occurred yet.
     */
    public function onErasureBeforeStart(ErasureBeforeStart|string $behaviour): self
    {
        $this->erasureBeforeStart = $behaviour instanceof ErasureBeforeStart
            ? $behaviour
            : ErasureBeforeStart::tryFrom($behaviour) ?? throw InvalidRetentionPolicy::unknownValue($this->category, 'erasure behaviour', $behaviour, ErasureBeforeStart::cases());

        return $this;
    }

    /**
     * @param  list<string>  $existing
     * @param  list<string>|string  $added
     * @return list<string>
     */
    private static function merge(array $existing, array|string $added): array
    {
        return array_values(array_unique([...$existing, ...(array) $added]));
    }
}
