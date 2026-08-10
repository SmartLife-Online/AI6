<?php

namespace App\AI6\Git;

use App\AI6\Projects\Models\Project;
use App\AI6\Shared\Redaction\RedactionContext;

interface ControlRemoteProbe
{
    public function resolve(Project $project, string $ref, RedactionContext $context): string;
}
