<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Rules;

use Illuminate\Contracts\Translation\Translator;

/**
 * Localised wording of a shipped rule (label, legal basis, erasure ground,
 * note). A value the application has overridden is never replaced by a
 * translation of the shipped text.
 */
final class RuleText
{
    /**
     * @param  'label'|'legal_basis'|'erasure_ground'|'note'  $field
     */
    public static function translate(string $ruleKey, string $field, ?string $value, ?string $locale = null): ?string
    {
        if ($value === null || (ShippedRules::definitions()[$ruleKey][$field] ?? null) !== $value) {
            return $value;
        }

        $translator = app(Translator::class);
        $key = "retention::rules.{$ruleKey}.{$field}";
        $translated = $translator->get($key, [], $locale);

        return is_string($translated) && $translated !== $key ? $translated : $value;
    }
}
