<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Execution;

/**
 * How a start column stores its point in time.
 */
enum TemporalType
{
    /** A calendar date, taken in the retention timezone. */
    case Date;

    /** Date and time without zone, written as wall-clock time in the application timezone (Laravel's default). */
    case DateTime;

    /** Date and time with zone (e.g. PostgreSQL timestamptz). */
    case DateTimeWithZone;

    /** Seconds since the Unix epoch in an integer column. */
    case UnixTimestamp;

    /**
     * Classifies a database column type name; null if it cannot hold a start event.
     */
    public static function fromColumnType(string $type): ?self
    {
        return match (strtolower($type)) {
            'date' => self::Date,
            'datetime', 'datetime2', 'smalldatetime', 'timestamp', 'timestamp without time zone' => self::DateTime,
            'timestamptz', 'timestamp with time zone', 'datetimeoffset' => self::DateTimeWithZone,
            'integer', 'int', 'int4', 'int8', 'bigint', 'mediumint', 'smallint' => self::UnixTimestamp,
            default => null,
        };
    }
}
