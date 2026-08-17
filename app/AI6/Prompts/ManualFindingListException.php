<?php

namespace App\AI6\Prompts;

use RuntimeException;

final class ManualFindingListException extends RuntimeException
{
    public function __construct(public readonly ManualFindingListError $reason)
    {
        parent::__construct('Manual finding list extraction failed: '.$reason->value.'.');
    }
}
