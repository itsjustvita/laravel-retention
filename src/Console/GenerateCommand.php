<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Console;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use ItsJustVita\Retention\Document\ConceptBuilder;
use ItsJustVita\Retention\Document\ConceptRenderer;
use ItsJustVita\Retention\Document\DeletionConcept;
use ItsJustVita\Retention\Exceptions\InvalidRetentionPolicy;
use ItsJustVita\Retention\Execution\ModelDiscovery;
use ItsJustVita\Retention\Policies\PolicyValidator;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'retention:generate')]
final class GenerateCommand extends Command
{
    private const array FORMATS = ['md', 'html', 'pdf'];

    private const array LOCALES = ['en', 'de'];

    protected $signature = 'retention:generate
        {--format=md : Output format: md, html or pdf (pdf needs barryvdh/laravel-dompdf)}
        {--locale= : Language of the document: en or de (default: the application locale, or en)}
        {--output= : Write to this file instead of printing the document}
        {--as-of= : Date for the cover page, YYYY-MM-DD (default: the date of the last Git commit)}';

    protected $description = 'Generate the deletion concept (DIN 66398) from the retention declarations';

    public function handle(ConceptBuilder $builder, ConceptRenderer $renderer, ModelDiscovery $discovery, PolicyValidator $validator): int
    {
        try {
            $format = $this->format();
            $locale = $this->locale();
            $asOf = $this->asOf();
            $output = $this->outputPath();

            if ($format === 'pdf' && $output === null) {
                throw new InvalidArgumentException('--format=pdf needs --output.');
            }

            if ($format === 'pdf' && ! $renderer->canRenderPdf()) {
                throw new InvalidArgumentException('PDF export needs an optional package: composer require barryvdh/laravel-dompdf. Alternatively, generate with --format=html and print the page.');
            }

            $validator->assertValid($discovery->retainedModels(), includeTablePolicies: true);
        } catch (InvalidRetentionPolicy $e) {
            $this->components->error('Retention policies are invalid; no document was generated.');

            foreach ($e->violations as $violation) {
                $this->line("  - {$violation}");
            }

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $concept = $builder->build($asOf);
        $document = $format === 'pdf' ? $renderer->renderPdf($concept, $locale) : $renderer->render($concept, $format, $locale);

        if ($output === null) {
            $this->output->write($document, false, $this->output::OUTPUT_RAW);

            return self::SUCCESS;
        }

        file_put_contents($output, $document);

        $this->warnAboutMissingCoverData($concept);
        $this->components->info("Deletion concept written to {$output}.");

        return self::SUCCESS;
    }

    private function warnAboutMissingCoverData(DeletionConcept $concept): void
    {
        $missing = [
            'organisation' => $concept->organisation,
            'controller' => $concept->controller,
            'data_protection_officer' => $concept->dataProtectionOfficer,
        ];

        foreach ($missing as $key => $value) {
            if ($value === null) {
                $this->components->warn("retention.document.{$key} is not set; the cover page is incomplete.");
            }
        }
    }

    /**
     * @return 'md'|'html'|'pdf'
     */
    private function format(): string
    {
        $format = $this->option('format');

        return match ($format) {
            'md', 'html', 'pdf' => $format,
            default => throw new InvalidArgumentException('--format must be one of '.implode(', ', self::FORMATS).'.'),
        };
    }

    private function locale(): string
    {
        $locale = $this->option('locale');

        if ($locale === null) {
            return in_array($this->laravel->getLocale(), self::LOCALES, true) ? $this->laravel->getLocale() : 'en';
        }

        if (! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException('--locale must be one of '.implode(', ', self::LOCALES).'.');
        }

        return (string) $locale;
    }

    private function asOf(): ?CarbonImmutable
    {
        $value = $this->option('as-of');

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('--as-of must be a date in the format YYYY-MM-DD.');
        }

        return CarbonImmutable::instance($date);
    }

    private function outputPath(): ?string
    {
        $value = $this->option('output');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
