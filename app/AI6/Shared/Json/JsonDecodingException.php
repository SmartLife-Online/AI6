<?php

namespace App\AI6\Shared\Json;

use RuntimeException;

final class JsonDecodingException extends RuntimeException
{
    public function __construct(public readonly JsonDecodingError $reason)
    {
        parent::__construct('JSON decoding failed: '.$reason->value.'.');
    }
}
