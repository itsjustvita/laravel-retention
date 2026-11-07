<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('source files declare strict types')
    ->expect('ItsJustVita\Retention')
    ->toUseStrictTypes();
