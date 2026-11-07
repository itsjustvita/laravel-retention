<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Document;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Translation\Translator;
use ItsJustVita\Retention\Rules\Period;
use ItsJustVita\Retention\Rules\RetentionRule;
use ItsJustVita\Retention\Rules\RuleText;

/**
 * Wording helpers for the deletion concept templates, in the current locale.
 */
final class ConceptText
{
    public function period(Period $period): string
    {
        $parts = [
            'year' => (int) floor($period->months / 12),
            'month' => $period->months % 12,
            'day' => $period->days,
            'hour' => (int) floor($period->seconds / 3600),
            'minute' => (int) floor($period->seconds % 3600 / 60),
            'second' => $period->seconds % 60,
        ];

        $words = [];

        foreach ($parts as $unit => $count) {
            if ($count > 0) {
                $words[] = $this->units($unit, $count);
            }
        }

        return $words === [] ? $this->units('day', 0) : implode(' ', $words);
    }

    public function date(CarbonInterface $date): string
    {
        return $date->format($this->text('date_format'));
    }

    public function dateString(string $date): string
    {
        return CarbonImmutable::parse($date)->format($this->text('date_format'));
    }

    public function text(string $key): string
    {
        $text = app(Translator::class)->get("retention::concept.{$key}");

        return is_string($text) ? $text : $key;
    }

    /**
     * @param  'label'|'legal_basis'|'erasure_ground'|'note'  $field
     */
    public function rule(RetentionRule $rule, string $field): ?string
    {
        return RuleText::translate($rule->key, $field, match ($field) {
            'label' => $rule->label,
            'legal_basis' => $rule->legalBasis,
            'erasure_ground' => $rule->erasureGround,
            'note' => $rule->note,
        });
    }

    /**
     * Earlier versions of a rule, newest first: valid until when, how long.
     *
     * @return list<array{until: string, period: string}>
     */
    public function previousVersions(RetentionRule $rule): array
    {
        $versions = [];

        for ($version = $rule; $version->previous !== null; $version = $version->previous) {
            $versions[] = [
                'until' => $this->dateString((string) $version->validFrom),
                'period' => $this->period($version->previous->period),
            ];
        }

        return $versions;
    }

    /**
     * A text configured for the document (string or array by locale), or the
     * boilerplate.
     *
     * @param  string|array<mixed>|null  $configured
     */
    public function configured(string|array|null $configured, string $boilerplateKey): string
    {
        if (is_array($configured)) {
            $configured = $configured[app()->getLocale()] ?? null;
        }

        return is_string($configured) && $configured !== '' ? $configured : $this->text($boilerplateKey);
    }

    /**
     * Makes a value safe for a Markdown table cell.
     */
    public function cell(?string $value): string
    {
        return str_replace(['|', "\r\n", "\n"], ['\|', ' ', ' '], (string) $value);
    }

    /**
     * @param  list<array{label: string, endOfYear: bool}>  $cell
     */
    public function matrixCell(array $cell): string
    {
        return implode(', ', array_map(fn (array $item): string => $item['label'].($item['endOfYear'] ? '*' : ''), $cell));
    }

    private function units(string $unit, int $count): string
    {
        return app(Translator::class)->choice("retention::concept.units.{$unit}", $count);
    }
}
