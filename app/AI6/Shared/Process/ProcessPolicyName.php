<?php

namespace App\AI6\Shared\Process;

enum ProcessPolicyName: string
{
    case CONTROL = 'control';
    case AGENT = 'agent';
    case CHECKER = 'checker';
}
