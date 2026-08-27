<?php

namespace App\AI6\Git;

enum ReviewSubjectKind: string
{
    case MANAGED_BRANCH = 'managed_branch';
    case COMMIT_RANGE = 'commit_range';
    case SINGLE_COMMIT = 'single_commit';
    case VALIDATED_PATCH = 'validated_patch';
    case CHECKPOINT = 'checkpoint';
}
