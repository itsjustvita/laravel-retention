<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ItsJustVita\Retention\Concerns\HasRetention;
use ItsJustVita\Retention\RetentionPolicy;
use LogicException;

/**
 * A model whose policy and table are set per test, to probe validation.
 */
class PolicyProbe extends Model
{
    use HasRetention;

    /** @var (Closure(): RetentionPolicy)|null */
    public static ?Closure $policy = null;

    public static string $probeTable = 'customers';

    public function getTable(): string
    {
        return static::$probeTable;
    }

    public static function retentionPolicy(): RetentionPolicy
    {
        return (static::$policy ?? throw new LogicException('No probe policy set.'))();
    }

    public function anonymizeForRetention(): void {}

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    public function notARelation(): string
    {
        return 'not a relation';
    }
}
