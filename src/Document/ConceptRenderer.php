<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Document;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Application;
use LogicException;

/**
 * Renders the deletion concept through the publishable Blade templates.
 */
final readonly class ConceptRenderer
{
    public function __construct(
        private Factory $views,
        private Container $container,
    ) {}

    /**
     * @param  'md'|'html'  $format
     */
    public function render(DeletionConcept $concept, string $format, string $locale): string
    {
        $application = $this->container->make(Application::class);
        $previous = $application->getLocale();
        $application->setLocale($locale);

        try {
            return $this->views->make("retention::concept.{$format}", [
                'concept' => $concept,
                'text' => new ConceptText,
                'locale' => $locale,
            ])->render();
        } finally {
            $application->setLocale($previous);
        }
    }

    public function canRenderPdf(): bool
    {
        return $this->container->bound('dompdf.wrapper');
    }

    public function renderPdf(DeletionConcept $concept, string $locale): string
    {
        $pdf = $this->container->make('dompdf.wrapper');

        if (! is_object($pdf) || ! method_exists($pdf, 'loadHTML')) {
            throw new LogicException('PDF export needs barryvdh/laravel-dompdf.');
        }

        $document = $pdf->loadHTML($this->render($concept, 'html', $locale))->output();

        return is_string($document) ? $document : '';
    }
}
