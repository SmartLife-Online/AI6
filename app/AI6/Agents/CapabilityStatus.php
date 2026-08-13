<?php

namespace App\AI6\Agents;

enum CapabilityStatus: string
{
    case AVAILABLE = 'available';
    case UNAVAILABLE = 'unavailable';
    case UNCHECKED = 'unchecked';

    public function selectable(): bool
    {
        return $this === self::AVAILABLE;
    }
}
