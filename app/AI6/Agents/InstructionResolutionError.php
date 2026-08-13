<?php

namespace App\AI6\Agents;

enum InstructionResolutionError: string
{
    case PROFILE_UNKNOWN = 'profile_unknown';
    case DISCOVERY_UNKNOWN = 'discovery_unknown';
    case HOST_SOURCE_FORBIDDEN = 'host_source_forbidden';
    case PARENT_SOURCE_FORBIDDEN = 'parent_source_forbidden';
    case FILE_MISSING = 'file_missing';
    case SYMLINK_FORBIDDEN = 'symlink_forbidden';
    case PATH_INVALID = 'path_invalid';
    case BLOB_SHA_INVALID = 'blob_sha_invalid';
    case UTF8_INVALID = 'utf8_invalid';
    case FILE_COUNT_EXCEEDED = 'file_count_exceeded';
    case FILE_BYTES_EXCEEDED = 'file_bytes_exceeded';
    case TOTAL_BYTES_EXCEEDED = 'total_bytes_exceeded';
    case IMPORT_DEPTH_EXCEEDED = 'import_depth_exceeded';
    case IMPORT_TARGET_MISSING = 'import_target_missing';
    case IMPORT_CYCLE = 'import_cycle';
    case PATH_DUPLICATE = 'path_duplicate';
}
