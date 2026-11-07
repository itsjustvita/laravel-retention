<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * Where a retention rule comes from.
 */
enum RuleType: string
{
    /** Required or justified by statute. */
    case Legal = 'legal';

    /** Common practice or guidance, not a statutory period. */
    case Recommendation = 'recommendation';

    /** Defined by the controller. */
    case Custom = 'custom';
}
