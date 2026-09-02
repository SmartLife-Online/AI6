<?php

namespace App\AI6\Runs;

enum ApprovalQueueState: string
{
    case PENDING_APPROVAL_EFFECT = 'pending_approval_effect';
    case AVAILABLE = 'available';
    case QUEUED = 'queued';
    case CONSUMED = 'consumed';
    case CANCELLED = 'cancelled';
}
