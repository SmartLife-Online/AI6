<?php

namespace App\AI6\Reviews;

enum ReviewInvocationOutcome: string
{
    case VALID_RESULT = 'valid_result';
    case NEEDS_HUMAN = 'needs_human';
    case PROVIDER_ERROR = 'provider_error';
    case INVALID_JSON = 'invalid_json';
    case BINDING_ERROR = 'binding_error';
    case CHECKPOINT_ERROR = 'checkpoint_error';
    case WORKSPACE_ERROR = 'workspace_error';
    case HUMAN_REQUEST_ERROR = 'human_request_error';

    public function terminal(): bool
    {
        return ! in_array($this, [self::NEEDS_HUMAN, self::PROVIDER_ERROR, self::INVALID_JSON], true);
    }
}
