<?php

namespace App\AI6\Git;

enum ControlOperationPhase: string
{
    case QUEUED = 'queued';
    case CLAIMED = 'claimed';
    case REMOTE_PROBED = 'remote_probed';
    case LAUNCH_INTENT = 'launch_intent';
    case PROCESS_STARTED = 'process_started';
    case KEY_GENERATED = 'key_generated';
    case KEY_ACTIVATED = 'key_activated';
    case PROVISIONING_FINALIZED = 'provisioning_finalized';
    case EFFECT_STAGED = 'effect_staged';
    case OUTCOME_PUBLISHED = 'outcome_published';
    case BINDING_FINALIZED = 'binding_finalized';
    case ATTEMPT_COMPLETED = 'attempt_completed';
    case RECOVERY_REQUIRED = 'recovery_required';
}
