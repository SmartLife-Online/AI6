<?php

namespace App\AI6\Checks;

use RuntimeException;

/**
 * A prior delivery of this very execution attempt died between its mailbox
 * order and its result.
 *
 * The state is named instead of silently skipped: without a result row the
 * check never ran, so treating the collision as "already done" would let a run
 * reach review without the check its configuration demands.
 */
final class OrphanedCheckExecution extends RuntimeException
{
    public function __construct(public readonly string $resultKey, public readonly int $deliveryAttempt)
    {
        parent::__construct('A previous delivery of this check attempt left no result.');
    }
}
