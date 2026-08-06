<?php

namespace App\AI6\Git;

enum RecoveryDecisionType: string
{
    case RETRY_RECONCILIATION = 'retry_reconciliation';
    case ADOPT_EXTERNAL_STATE = 'adopt_external_state';
    case ABANDON_OPERATION = 'abandon_operation';
}
