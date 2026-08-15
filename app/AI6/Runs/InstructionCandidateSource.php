<?php

namespace App\AI6\Runs;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Projects\Models\Project;
use App\AI6\Shared\Redaction\RedactionContext;

interface InstructionCandidateSource
{
    /**
     * @param  list<string>  $ticketFiles
     * @return list<InstructionCandidate>
     */
    public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array;
}
