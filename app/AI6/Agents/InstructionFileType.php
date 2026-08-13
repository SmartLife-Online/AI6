<?php

namespace App\AI6\Agents;

enum InstructionFileType: string
{
    case REGULAR = 'regular';
    case SYMLINK = 'symlink';
}
