<?php

declare(strict_types=1);

namespace ItsJustVita\Retention;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\Retention\Erasure\ErasureProcessor;
use ItsJustVita\Retention\Erasure\PendingErasure;

/**
 * Entry point for erasure requests under Art. 17 GDPR:
 *
 *     $report = Erasure::for($customer)->including(['invoices', 'orders.invoices'])->execute();
 *     $reply = $report->toMarkdown('de');
 */
final class Erasure
{
    public static function for(Model $subject): PendingErasure
    {
        return new PendingErasure($subject, app(ErasureProcessor::class));
    }
}
