<?php

namespace App\AI6\Git;

enum RunPatchStatus: string
{
    case ADDED = 'added';
    case MODIFIED = 'modified';
    case DELETED = 'deleted';
}
