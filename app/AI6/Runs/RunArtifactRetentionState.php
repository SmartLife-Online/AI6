<?php

namespace App\AI6\Runs;

/** Whether the raw bytes of a run artifact are still stored or already removed by retention. */
enum RunArtifactRetentionState: string
{
    case STORED = 'stored';
    case DELETED = 'deleted';
}
