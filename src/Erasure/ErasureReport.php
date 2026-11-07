<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Erasure;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Enums\ErasureStatus;

/**
 * Result of an erasure request, per data category, in processing order.
 *
 * toMarkdown() renders a reply for the data subject; it lists counts, dates
 * and legal grounds, never record keys.
 */
final readonly class ErasureReport
{
    /** Order of the lines within a category in toMarkdown(). */
    private const array STATUS_ORDER = [
        ErasureStatus::Deleted,
        ErasureStatus::Anonymized,
        ErasureStatus::Restricted,
        ErasureStatus::OnHold,
        ErasureStatus::StartEventPending,
        ErasureStatus::Cancelled,
        ErasureStatus::NoPolicy,
    ];

    /**
     * @param  class-string<Model>  $subjectModel
     * @param  list<ErasureEntry>  $entries
     */
    public function __construct(
        public string $subjectModel,
        public int|string $subjectKey,
        public CarbonImmutable $processedAt,
        public bool $dryRun,
        public array $entries,
    ) {}

    /**
     * @return list<ErasureEntry>
     */
    public function withStatus(ErasureStatus $status): array
    {
        return array_values(array_filter($this->entries, fn (ErasureEntry $entry): bool => $entry->status === $status));
    }

    /**
     * @return array{
     *     subject: array{model: class-string<Model>, key: int|string},
     *     processed_at: string,
     *     dry_run: bool,
     *     categories: array<string, array{label: string, records: list<array{model: class-string<Model>, key: int|string, status: string, final_action: string|null, scheduled_for: string|null, waits_for_dependents: bool, erasure_ground: string|null}>}>
     * }
     */
    public function toArray(): array
    {
        $categories = [];

        foreach ($this->entries as $entry) {
            $key = $entry->category ?? $entry->model;
            $categories[$key]['label'] ??= $entry->label ?? class_basename($entry->model);
            $categories[$key]['records'][] = [
                'model' => $entry->model,
                'key' => $entry->key,
                'status' => $entry->status->value,
                'final_action' => $entry->finalAction?->value,
                'scheduled_for' => $entry->scheduledFor?->toIso8601String(),
                'waits_for_dependents' => $entry->waitsForDependents,
                'erasure_ground' => $entry->erasureGround,
            ];
        }

        return [
            'subject' => ['model' => $this->subjectModel, 'key' => $this->subjectKey],
            'processed_at' => $this->processedAt->toIso8601String(),
            'dry_run' => $this->dryRun,
            'categories' => $categories,
        ];
    }

    /**
     * A reply for the data subject (en and de shipped; publishable).
     */
    public function toMarkdown(?string $locale = null): string
    {
        $translator = app(Translator::class);
        $t = fn (string $key, array $replace = []): string => self::string($translator->get("retention::erasure.{$key}", $replace, $locale));
        $dateFormat = $t('date_format');

        $blocks = [
            '# '.$t('title'),
            $t('processed_at', ['date' => $this->processedAt->format($dateFormat)]),
        ];

        if ($this->dryRun) {
            $blocks[] = $t('dry_run');
        }

        $byLabel = [];

        foreach ($this->entries as $entry) {
            $byLabel[$entry->label ?? class_basename($entry->model)][] = $entry;
        }

        ksort($byLabel);

        foreach ($byLabel as $label => $entries) {
            $blocks[] = '## '.$label;
            $blocks[] = implode("\n", array_map(
                fn (array $group): string => '- '.$this->line($group, $t, $translator, $locale, $dateFormat),
                $this->groups($entries),
            ));
        }

        return implode("\n\n", $blocks)."\n";
    }

    /**
     * Groups entries that produce the same sentence.
     *
     * @param  list<ErasureEntry>  $entries
     * @return list<non-empty-list<ErasureEntry>>
     */
    private function groups(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $groups[implode('|', [
                array_search($entry->status, self::STATUS_ORDER, true),
                $entry->scheduledFor?->format('Y-m-d') ?? '',
                $entry->waitsForDependents ? 'dependents' : '',
                $entry->erasureGround ?? '',
                $entry->finalAction->value ?? '',
            ])][] = $entry;
        }

        ksort($groups);

        return array_values($groups);
    }

    /**
     * @param  non-empty-list<ErasureEntry>  $group
     * @param  callable(string, array<string, string>=): string  $t
     */
    private function line(array $group, callable $t, Translator $translator, ?string $locale, string $dateFormat): string
    {
        $entry = $group[0];
        $records = self::string($translator->choice('retention::erasure.records', count($group), [], $locale));

        if ($entry->status !== ErasureStatus::Restricted) {
            return $t('status.'.$entry->status->value, ['records' => $records]);
        }

        $action = $t('actions.'.($entry->finalAction->value ?? 'delete'));

        if ($entry->waitsForDependents || $entry->scheduledFor === null) {
            return $t('status.restricted_dependent', ['records' => $records, 'action' => $action]);
        }

        return $t('status.restricted', [
            'records' => $records,
            'date' => $entry->scheduledFor->format($dateFormat),
            'ground' => (string) $entry->erasureGround,
            'action' => $action,
        ]);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
