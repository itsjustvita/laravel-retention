<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use ItsJustVita\Retention\Concerns\Restrictable;

/**
 * PolicyProbe with the Restrictable trait; shares the probe's policy and table.
 */
class RestrictableProbe extends PolicyProbe
{
    use Restrictable;
}
