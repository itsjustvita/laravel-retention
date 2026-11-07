<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures;

use ItsJustVita\Retention\Tests\Fixtures\Models\Customer;

class CustomerAnonymizer
{
    public function __invoke(Customer $customer): void
    {
        $customer->forceFill(['name' => 'deleted', 'email' => null, 'phone' => null])->saveQuietly();
    }
}
