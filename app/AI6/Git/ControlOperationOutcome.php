<?php

namespace App\AI6\Git;

enum ControlOperationOutcome: string
{
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case ABANDONED = 'abandoned';
}
