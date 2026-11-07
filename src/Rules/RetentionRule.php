<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Rules;

use DateTimeImmutable;
use InvalidArgumentException;
use ItsJustVita\Retention\Enums\Counting;
use ItsJustVita\Retention\Enums\RuleType;
use ItsJustVita\Retention\Exceptions\InvalidRuleDefinition;

/**
 * A retention rule: period, period counting, legal basis and erasure behaviour.
 *
 * A rule may have a previous version that applied before `validFrom`. Which
 * version applies to a record is decided by the evaluation date, see
 * RetentionCalculator.
 */
final readonly class RetentionRule
{
    private const array KEYS = [
        'label',
        'period',
        'counting',
        'legal_basis',
        'type',
        'blocks_erasure',
        'erasure_ground',
        'valid_from',
        'previous',
        'note',
        'as_of',
    ];

    /**
     * @param  string|null  $validFrom  Date (YYYY-MM-DD) from which this version applies.
     * @param  string|null  $asOf  Date (YYYY-MM-DD) on which the legal content was last verified.
     */
    public function __construct(
        public string $key,
        public string $label,
        public Period $period,
        public Counting $counting,
        public string $legalBasis,
        public RuleType $type,
        public bool $blocksErasure,
        public ?string $erasureGround = null,
        public ?string $validFrom = null,
        public ?self $previous = null,
        public ?string $note = null,
        public ?string $asOf = null,
    ) {
        if ($blocksErasure && $erasureGround === null) {
            throw InvalidRuleDefinition::for($key, '[erasure_ground] is required when [blocks_erasure] is true.');
        }

        if ($period->isTimeBased() && $counting !== Counting::Event) {
            throw InvalidRuleDefinition::for($key, '[period] is time-based and requires [counting] event.');
        }

        if ($previous !== null) {
            if ($validFrom === null) {
                throw InvalidRuleDefinition::for($key, '[previous] requires [valid_from].');
            }

            if ($previous->validFrom !== null && $previous->validFrom >= $validFrom) {
                throw InvalidRuleDefinition::for($key, '[previous][valid_from] must be before [valid_from].');
            }
        }
    }

    /**
     * Builds a rule from its config definition. `previous` holds only the keys
     * that differed before `valid_from`; all other keys are inherited.
     *
     * @param  array<mixed>  $definition
     */
    public static function fromArray(string $key, array $definition): self
    {
        $unknown = array_diff(array_map(strval(...), array_keys($definition)), self::KEYS);

        if ($unknown !== []) {
            throw InvalidRuleDefinition::for($key, 'unknown keys ['.implode(', ', $unknown).'].');
        }

        return new self(
            key: $key,
            label: self::requiredString($key, $definition, 'label'),
            period: self::period($key, $definition),
            counting: self::counting($key, $definition),
            legalBasis: self::requiredString($key, $definition, 'legal_basis'),
            type: self::type($key, $definition),
            blocksErasure: self::boolean($key, $definition, 'blocks_erasure'),
            erasureGround: self::optionalString($key, $definition, 'erasure_ground'),
            validFrom: self::date($key, $definition, 'valid_from'),
            previous: self::previous($key, $definition),
            note: self::optionalString($key, $definition, 'note'),
            asOf: self::date($key, $definition, 'as_of'),
        );
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function requiredString(string $key, array $definition, string $field): string
    {
        $value = $definition[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw InvalidRuleDefinition::for($key, "[{$field}] must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function optionalString(string $key, array $definition, string $field): ?string
    {
        $value = $definition[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidRuleDefinition::for($key, "[{$field}] must be a string.");
        }

        return trim($value) === '' ? null : $value;
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function boolean(string $key, array $definition, string $field): bool
    {
        $value = $definition[$field] ?? null;

        if (! is_bool($value)) {
            throw InvalidRuleDefinition::for($key, "[{$field}] must be a boolean.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function date(string $key, array $definition, string $field): ?string
    {
        $value = $definition[$field] ?? null;

        if ($value === null) {
            return null;
        }

        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw InvalidRuleDefinition::for($key, "[{$field}] must be a date in the format YYYY-MM-DD.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function period(string $key, array $definition): Period
    {
        $value = $definition['period'] ?? null;

        if (! is_string($value)) {
            throw InvalidRuleDefinition::for($key, '[period] must be an ISO 8601 duration such as P8Y or PT72H.');
        }

        try {
            return Period::parse($value);
        } catch (InvalidArgumentException $e) {
            throw InvalidRuleDefinition::for($key, '[period] '.$e->getMessage(), $e);
        }
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function counting(string $key, array $definition): Counting
    {
        $value = $definition['counting'] ?? null;

        return (is_string($value) ? Counting::tryFrom($value) : null)
            ?? throw InvalidRuleDefinition::for($key, '[counting] must be one of ['.self::values(Counting::cases()).'].');
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function type(string $key, array $definition): RuleType
    {
        $value = $definition['type'] ?? null;

        return (is_string($value) ? RuleType::tryFrom($value) : null)
            ?? throw InvalidRuleDefinition::for($key, '[type] must be one of ['.self::values(RuleType::cases()).'].');
    }

    /**
     * @param  array<mixed>  $definition
     */
    private static function previous(string $key, array $definition): ?self
    {
        $previous = $definition['previous'] ?? null;

        if ($previous === null) {
            return null;
        }

        if (! is_array($previous)) {
            throw InvalidRuleDefinition::for($key, '[previous] must be an array of the keys that differed before [valid_from].');
        }

        $inherited = array_diff_key($definition, ['previous' => true, 'valid_from' => true]);

        try {
            return self::fromArray($key, array_replace($inherited, $previous));
        } catch (InvalidRuleDefinition $e) {
            throw InvalidRuleDefinition::for($key, '[previous] '.$e->reason, $e);
        }
    }

    /**
     * @param  list<\BackedEnum>  $cases
     */
    private static function values(array $cases): string
    {
        return implode(', ', array_map(fn (\BackedEnum $case): string => (string) $case->value, $cases));
    }
}
