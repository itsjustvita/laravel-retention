<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ItsJustVita\Retention\Document\GitInfo;
use Symfony\Component\Process\Process;

it('reads the last commit of a repository', function () {
    $directory = sys_get_temp_dir().'/retention-git-'.uniqid();
    mkdir($directory);

    foreach ([
        ['git', 'init', '-q'],
        ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '--allow-empty', '-m', 'Test'],
    ] as $command) {
        (new Process($command, $directory))->mustRun();
    }

    $commit = (new GitInfo($directory))->lastCommit();

    expect($commit)->not->toBeNull()
        ->and($commit['hash'] ?? '')->toMatch('/^[0-9a-f]{40}$/')
        ->and($commit['date'] ?? null)->toBeInstanceOf(CarbonImmutable::class);

    (new Process(['rm', '-rf', $directory]))->mustRun();
});

it('returns nothing outside a repository', function () {
    $directory = sys_get_temp_dir().'/retention-no-git-'.uniqid();
    mkdir($directory);

    expect((new GitInfo($directory))->lastCommit())->toBeNull();

    rmdir($directory);
});
