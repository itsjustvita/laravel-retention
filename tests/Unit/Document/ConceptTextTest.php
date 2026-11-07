<?php

declare(strict_types=1);

use ItsJustVita\Retention\Document\ConceptText;
use ItsJustVita\Retention\Rules\Period;

it('writes out periods', function (string $iso, string $locale, string $expected) {
    app()->setLocale($locale);

    expect((new ConceptText)->period(Period::parse($iso)))->toBe($expected);
})->with([
    ['P8Y', 'en', '8 years'],
    ['P1Y', 'en', '1 year'],
    ['P1Y6M', 'en', '1 year 6 months'],
    ['P6M', 'en', '6 months'],
    ['P7D', 'en', '7 days'],
    ['P1D', 'en', '1 day'],
    ['P0D', 'en', '0 days'],
    ['PT72H', 'en', '72 hours'],
    ['PT90M', 'en', '1 hour 30 minutes'],
    ['P8Y', 'de', '8 Jahre'],
    ['P1Y', 'de', '1 Jahr'],
    ['P1Y6M', 'de', '1 Jahr 6 Monate'],
    ['P7D', 'de', '7 Tage'],
    ['PT72H', 'de', '72 Stunden'],
]);

it('escapes table cells for Markdown', function () {
    expect((new ConceptText)->cell("a | b\nc"))->toBe('a \| b c');
});
