<?php

namespace App\AI6\Prompts;

enum PromptRenderingError: string
{
    case ENTRY_UNKNOWN = 'entry_unknown';
    case REVIEW_PROFILE_UNKNOWN = 'review_profile_unknown';
    case VARIABLES_INVALID = 'variables_invalid';
    case INPUT_BYTES_EXCEEDED = 'input_bytes_exceeded';
    case UTF8_INVALID = 'utf8_invalid';
    case DUPLICATE_ENTRY = 'duplicate_entry';
    case CATALOG_VERSION_NOT_INCREASED = 'catalog_version_not_increased';
}
