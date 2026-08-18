<?php

namespace App\AI6\Runs;

enum RunArtifactKind: string
{
    case IMPLEMENTATION_SUMMARY = 'implementation_summary';
    case PROVIDER_RAW = 'provider_raw';
    case LIMIT_PENDING = 'limit_pending';
    case LIMIT_GRANT = 'limit_grant';
}
