<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Tests\Fixtures\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
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

    /** @var list<bool> */
    public static array $untypedCalls = [];

    public function getTable(): string
    {
        return static::$probeTable;
    }

    public static function retentionPolicy(): RetentionPolicy
    {
        return (static::$policy ?? throw new LogicException('No probe policy set.'))();
    }

    public function anonymizeForRetention(): void
    {
        $this->forceFill(['name' => 'deleted'])->saveQuietly();
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    /**
     * A relation to a model without HasRetention.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id');
    }

    /**
     * A relation without a native return type, as in older code bases.
     *
     * @return HasMany<Invoice, $this>
     */
    public function untypedInvoices()
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    /**
     * A method without a native return type that is not a relation. Records
     * whether it ran on a saved record.
     *
     * @return string
     */
    public function untypedNotARelation()
    {
        static::$untypedCalls[] = $this->exists;

        return 'not a relation';
    }

    public function notARelation(): string
    {
        return 'not a relation';
    }
}
