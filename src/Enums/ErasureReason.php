<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * Why a rule keeps data despite an erasure request. Decides the wording of the
 * reply to the data subject.
 */
enum ErasureReason: string
{
    /** A legal obligation requires keeping the data (Art. 17 (3) (b) GDPR). */
    case LegalDuty = 'legal_duty';

    /** The data is needed to establish, exercise or defend legal claims (Art. 17 (3) (e) GDPR). */
    case LegalClaims = 'legal_claims';
}
