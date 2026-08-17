<?php

namespace App\AI6\Prompts;

enum ManualFindingListError: string
{
    case UTF8_INVALID = 'utf8_invalid';
    case INPUT_BYTES_EXCEEDED = 'input_bytes_exceeded';
    case MARKER_MISSING = 'marker_missing';
    case MARKER_DUPLICATE = 'marker_duplicate';
    case MARKER_NOT_TERMINAL = 'marker_not_terminal';
    case LIST_EMPTY = 'list_empty';
}
