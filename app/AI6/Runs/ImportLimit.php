<?php

namespace App\AI6\Runs;

enum ImportLimit: string
{
    case MAX_CHANGED_FILES = 'max_changed_files';
    case MAX_CHANGED_BYTES = 'max_changed_bytes';
    case MAX_ARTIFACTS = 'max_artifacts';
    case MAX_ARTIFACT_BYTES = 'max_artifact_bytes';
    case MAX_TOTAL_ARTIFACT_BYTES = 'max_total_artifact_bytes';
    case MAX_PROVIDER_OUTPUT_BYTES = 'max_provider_output_bytes';
}
