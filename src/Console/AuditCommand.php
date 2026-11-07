<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Console;

use Illuminate\Console\Command;
use ItsJustVita\Retention\Audit\AuditFinding;
use ItsJustVita\Retention\Audit\RetentionAudit;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'retention:audit')]
final class AuditCommand extends Command
{
    protected $signature = 'retention:audit
        {--strict : Also fail on warnings}';

    protected $description = 'Check that every model and table has a retention policy or a justified exclusion';

    public function handle(RetentionAudit $audit): int
    {
        $report = $audit->run();

        $this->list('Errors', $report->errors());
        $this->list('Warnings', $report->warnings());

        if ($report->hasErrors()) {
            $this->components->error('Retention audit failed.');

            return self::FAILURE;
        }

        if ($this->option('strict') && $report->warnings() !== []) {
            $this->components->error('Retention audit failed on warnings (--strict).');

            return self::FAILURE;
        }

        $this->components->info('All models and tables are covered.');

        return self::SUCCESS;
    }

    /**
     * @param  list<AuditFinding>  $findings
     */
    private function list(string $title, array $findings): void
    {
        if ($findings === []) {
            return;
        }

        $this->line("{$title}:");

        foreach ($findings as $finding) {
            $this->line("  - {$finding->message}");
        }
    }
}
