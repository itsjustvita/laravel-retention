<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Document;

use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reads the last Git commit, which dates the generated document.
 */
class GitInfo
{
    public function __construct(
        private readonly string $path,
    ) {}

    /**
     * @return array{hash: string, date: CarbonImmutable}|null
     */
    public function lastCommit(): ?array
    {
        try {
            $process = new Process(['git', 'log', '-1', '--format=%H %cI'], $this->path, timeout: 5);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $parts = explode(' ', trim($process->getOutput()));

        return $process->isSuccessful() && count($parts) === 2
            ? ['hash' => $parts[0], 'date' => CarbonImmutable::parse($parts[1])]
            : null;
    }
}
