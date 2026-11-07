<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Log;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Pseudonymises record IDs for the deletion log with an HMAC.
 *
 * Uses a dedicated key (RETENTION_LOG_KEY) so that rotating APP_KEY does not
 * break verification; falls back to APP_KEY. Every entry stores the ID of the
 * key that produced it, so the log key can be rotated too.
 */
final readonly class LogHasher
{
    private string $key;

    private bool $usesApplicationKey;

    /** @var list<string> */
    private array $previousKeys;

    public function __construct(Repository $config)
    {
        $key = $config->get('retention.log.key');
        $applicationKey = $config->get('app.key');

        if (is_string($key) && $key !== '') {
            $this->key = $key;
            $this->usesApplicationKey = false;
        } elseif (is_string($applicationKey) && $applicationKey !== '') {
            $this->key = $applicationKey;
            $this->usesApplicationKey = true;
        } else {
            throw new RuntimeException('The deletion log needs a key to pseudonymise record IDs. Set RETENTION_LOG_KEY (or APP_KEY), or disable the log with retention.log.enabled.');
        }

        $previous = $config->get('retention.log.previous_keys') ?? [];
        $this->previousKeys = is_array($previous) ? array_values(array_filter($previous, fn (mixed $key): bool => is_string($key) && $key !== '')) : [];
    }

    /**
     * @return array{hash: string, key_id: string}
     */
    public function hash(string $model, int|string $id): array
    {
        return self::hashWith($this->key, $model, $id);
    }

    /**
     * Hashes with the current and all previous keys.
     *
     * @return list<array{hash: string, key_id: string}>
     */
    public function candidates(string $model, int|string $id): array
    {
        return array_map(
            fn (string $key): array => self::hashWith($key, $model, $id),
            [$this->key, ...$this->previousKeys],
        );
    }

    public function currentKeyId(): string
    {
        return self::keyId($this->key);
    }

    public function usesApplicationKey(): bool
    {
        return $this->usesApplicationKey;
    }

    /**
     * @return array{hash: string, key_id: string}
     */
    private static function hashWith(string $key, string $model, int|string $id): array
    {
        return [
            'hash' => hash_hmac('sha256', $model.'|'.$id, $key),
            'key_id' => self::keyId($key),
        ];
    }

    /**
     * Identifies a key without revealing it.
     */
    private static function keyId(string $key): string
    {
        return substr(hash_hmac('sha256', 'retention-log-key-id', $key), 0, 16);
    }
}
