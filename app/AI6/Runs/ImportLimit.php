<?php

namespace App\AI6\Runs;

enum ImportLimit: string
{
    case MAX_RUN_MINUTES = 'max_run_minutes';
    case MAX_AGENT_INVOCATIONS = 'max_agent_invocations';
    case MAX_REVIEW_ROUNDS = 'max_review_rounds';
    case MAX_FIX_ROUNDS = 'max_fix_rounds';
    case MAX_VERIFICATION_ROUNDS = 'max_verification_rounds';
    case MAX_CHANGED_FILES = 'max_changed_files';
    case MAX_CHANGED_BYTES = 'max_changed_bytes';
    case MAX_ARTIFACTS = 'max_artifacts';
    case MAX_ARTIFACT_BYTES = 'max_artifact_bytes';
    case MAX_TOTAL_ARTIFACT_BYTES = 'max_total_artifact_bytes';
    case MAX_PROVIDER_OUTPUT_BYTES = 'max_provider_output_bytes';
    case MAX_ADDED_SCOPE_PATHS = 'max_added_scope_paths';
}
