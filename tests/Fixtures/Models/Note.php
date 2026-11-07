<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model without retention policy.
 */
class Note extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
