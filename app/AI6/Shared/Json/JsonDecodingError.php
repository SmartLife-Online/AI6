<?php

namespace App\AI6\Shared\Json;

enum JsonDecodingError: string
{
    case INVALID_UTF8 = 'invalid_utf8';
    case SIZE_EXCEEDED = 'size_exceeded';
    case INVALID_JSON = 'invalid_json';
    case DUPLICATE_KEY = 'duplicate_key';
    case NESTING_EXCEEDED = 'nesting_exceeded';
    case ELEMENT_LIMIT_EXCEEDED = 'element_limit_exceeded';
}
