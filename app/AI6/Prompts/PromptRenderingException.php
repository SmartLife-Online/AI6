<?php

namespace App\AI6\Prompts;

use RuntimeException;

final class PromptRenderingException extends RuntimeException
{
    public function __construct(public readonly PromptRenderingError $reason)
    {
        parent::__construct('Prompt rendering failed: '.$reason->value.'.');
    }
}
