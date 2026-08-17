<?php

namespace App\AI6\Agents;

final readonly class AgentResult
{
    /**
     * @param  list<AgentFinding>  $findings
     * @param  list<CriterionCoverageEntry>  $criterionCoverage
     */
    public function __construct(
        public string $schemaVersion,
        public AgentResultStatus $status,
        public string $summary,
        public ?HumanRequestProposal $humanRequest,
        public array $findings,
        public array $criterionCoverage,
        public ?InstructionPatch $instructionPatch,
    ) {}
}
