<?php

namespace App\AI6\Agents;

enum InstructionCandidateOrigin: string
{
    case REPOSITORY = 'repository';
    case HOST = 'host';
    case PARENT = 'parent';
}
