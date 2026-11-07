<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\Enums\StartEvent;
use ItsJustVita\Retention\RetentionPolicy;

class ServerLog extends Model
{
    use HasRetention;

    public $timestamps = false;

    protected $guarded = [];

    public static function retentionPolicy(): RetentionPolicy
    {
        return RetentionPolicy::category('server_logs', 'Web server logs')
            ->rule('server_logs')
            ->startsAt('logged_at', StartEvent::Collection)
            ->action('delete')
            ->responsible('IT');
    }
}
