<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Document;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Application;

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

    /**
     * Only call this if canRenderPdf() is true.
     */
    public function renderPdf(DeletionConcept $concept, string $locale): string
    {
        return $this->container->make('dompdf.wrapper')
            ->loadHTML($this->render($concept, 'html', $locale))
            ->output();
    }
}
