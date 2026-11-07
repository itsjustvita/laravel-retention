<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * The columns the package adds to retained tables, and the Blueprint macros
 * that create them.
 */
final class RetentionColumns
{
    /** Set while processing is restricted (Art. 18 GDPR, § 35 BDSG). */
    public const string RESTRICTED_AT = 'restricted_at';

    /** Set once a record has been anonymised, so it is never processed again. */
    public const string ANONYMIZED_AT = 'anonymized_at';

    public static function registerMacros(): void
    {
        Blueprint::macro('restrictable', function (): void {
            /** @var Blueprint $this */
            $this->timestamp(RetentionColumns::RESTRICTED_AT)->nullable()->index();
        });

        Blueprint::macro('dropRestrictable', function (): void {
            /** @var Blueprint $this */
            $this->dropIndex([RetentionColumns::RESTRICTED_AT]);
            $this->dropColumn(RetentionColumns::RESTRICTED_AT);
        });

        Blueprint::macro('anonymizable', function (): void {
            /** @var Blueprint $this */
            $this->timestamp(RetentionColumns::ANONYMIZED_AT)->nullable()->index();
        });

        Blueprint::macro('dropAnonymizable', function (): void {
            /** @var Blueprint $this */
            $this->dropIndex([RetentionColumns::ANONYMIZED_AT]);
            $this->dropColumn(RetentionColumns::ANONYMIZED_AT);
        });
    }
}
