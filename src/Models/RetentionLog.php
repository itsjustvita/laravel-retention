<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use ItsJustVita\Retention\Log\LogHasher;

/**
 * An entry of the deletion log (evidence under Art. 5 (2) GDPR).
 *
 * The record ID is stored only as an HMAC. Because the controller holds the
 * key, the entries are pseudonymised, not anonymous (Recital 26 GDPR), and
 * have their own retention period (retention.log.retention). Rows deleted
 * through a table policy are logged in aggregate, with the table name as
 * `model`, the number of rows in `record_count` and no hash.
 *
 * @property int $id
 * @property string $model
 * @property string|null $record_hash
 * @property string|null $key_id
 * @property int $record_count
 * @property string $category
 * @property string $rule
 * @property string $action
 * @property string $trigger
 * @property Carbon|null $created_at
 */
class RetentionLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'retention_log';

    protected $guarded = [];

    /**
     * The log entries of a specific record, e.g. to answer a data subject who
     * knows their customer number. Also checks rotated keys
     * (retention.log.previous_keys).
     *
     * @param  class-string<Model>  $model
     * @return Collection<int, self>
     */
    public static function verify(string $model, int|string $id): Collection
    {
        $candidates = app(LogHasher::class)->candidates($model, $id);

        return self::query()
            ->where('model', $model)
            ->where(function (Builder $query) use ($candidates): void {
                foreach ($candidates as $candidate) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('key_id', $candidate['key_id'])
                        ->where('record_hash', $candidate['hash']));
                }
            })
            ->orderBy('id')
            ->get();
    }
}
