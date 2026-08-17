<?php

namespace App\AI6\Shared\Process;

enum ProcessLimit: string
{
    case RUNTIME_SECONDS = 'runtime_seconds';
    case OUTPUT_BYTES = 'output_bytes';
    case PROCESS_COUNT = 'process_count';
    case FILE_COUNT = 'file_count';
    case TOTAL_BYTES = 'total_bytes';
    case ARTIFACT_COUNT = 'artifact_count';
}
