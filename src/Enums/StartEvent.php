<?php

declare(strict_types=1);

namespace ItsJustVita\Retention\Enums;

/**
 * The business event that starts a retention period (DIN 66398
 * "Startzeitpunkt"). Together with the period it forms the deletion class.
 */
enum StartEvent: string
{
    /** The data was collected. */
    case Collection = 'collection';

    /** The business transaction is complete, e.g. an invoice was issued. */
    case EndOfTransaction = 'end_of_transaction';

    /** The business relationship ended, e.g. a contract was terminated. */
    case EndOfRelationship = 'end_of_relationship';
}
