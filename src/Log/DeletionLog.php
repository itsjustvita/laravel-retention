<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Log;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use ItsJustVita\Retention\Models\RetentionHold;
use ItsJustVita\Retention\Models\RetentionLog;
use ItsJustVita\Retention\Rules\Period;

/**
 * Settings and housekeeping of the deletion log.
 */
final readonly class DeletionLog
{
    public function __construct(
        private Repository $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('retention.log.enabled', true);
    }

    /**
     * Whether a key is configured to pseudonymise record IDs.
     */
    public function hasKey(): bool
    {
        foreach (['retention.log.key', 'app.key'] as $key) {
            $value = $this->config->get($key);

            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    public function retention(): Period
    {
        $value = $this->config->get('retention.log.retention', 'P3Y');

        if (! is_string($value)) {
            throw new InvalidArgumentException('[retention.log.retention] must be an ISO 8601 duration such as P3Y.');
        }

        return Period::parse($value);
    }

    /**
     * Deletes entries older than the log's retention period.
     *
     * @return int number of purged entries
     */
    public function purge(CarbonInterface $now): int
    {
        $cutoff = $this->retention()->subtractFrom(CarbonImmutable::instance($now));

        return RetentionLog::query()
            ->where('created_at', '<', (new RetentionLog)->fromDateTime(RetentionHold::inApplicationTimezone($cutoff)))
            ->delete();
    }
}
