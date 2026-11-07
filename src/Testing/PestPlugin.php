<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Testing;

use InvalidArgumentException;
use ItsJustVita\Retention\Policies\PolicyValidator;
use Pest\Contracts\Plugins\Bootable;
use Pest\Expectation;
use PHPUnit\Framework\Assert;

/**
 * Registers the package's Pest expectations. Pest discovers this plugin via
 * extra.pest.plugins in composer.json.
 *
 *     expect(Invoice::class)->toHaveValidRetentionPolicy();
 */
final class PestPlugin implements Bootable
{
    public function boot(): void
    {
        expect()->extend('toHaveValidRetentionPolicy', function (): Expectation {
            /** @var Expectation<mixed> $this */
            $model = $this->value;

            if (! is_string($model)) {
                throw new InvalidArgumentException('toHaveValidRetentionPolicy() expects a model class name.');
            }

            $violations = app(PolicyValidator::class)->violations($model);

            Assert::assertSame([], $violations, "Retention policy of [{$model}] is invalid:\n- ".implode("\n- ", $violations));

            return $this;
        });
    }
}
