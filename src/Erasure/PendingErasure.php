<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Erasure;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Exceptions\MissingRetentionPolicy;

/**
 * An erasure request being prepared: Erasure::for($customer)->including([...]).
 */
final class PendingErasure
{
    /** @var list<string> */
    private array $relations = [];

    public function __construct(
        private readonly Model $subject,
        private readonly ErasureProcessor $processor,
    ) {}

    /**
     * Related records to erase as well; dot notation for nested relations.
     *
     * @param  list<string>|string  $relations
     */
    public function including(array|string $relations): self
    {
        $this->relations = array_values(array_unique([...$this->relations, ...(array) $relations]));

        return $this;
    }

    /**
     * Erases the records in one transaction.
     *
     * @throws MissingRetentionPolicy if a related model has no policy; nothing is changed then
     * @throws InvalidRetentionPolicy if a policy is invalid; nothing is changed then
     */
    public function execute(): ErasureReport
    {
        return $this->processor->process($this->subject, $this->relations, dryRun: false);
    }

    /**
     * Reports what execute() would do, without changing anything.
     */
    public function dryRun(): ErasureReport
    {
        return $this->processor->process($this->subject, $this->relations, dryRun: true);
    }
}
