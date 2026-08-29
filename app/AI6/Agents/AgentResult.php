<?php

namespace App\AI6\Agents;

final readonly class AgentResult
{
    /**
     * @param  list<AgentFinding>  $findings
     * @param  list<CriterionCoverageEntry>  $criterionCoverage
     * @param  list<ImplementationDecision>  $decisions
     * @param  list<string>  $changedPaths
     * @param  list<string>  $openManualGates
     * @param  list<InstructionRecommendation>  $instructionRecommendations
     * @param  list<FindingStatusEntry>  $findingStatuses
     */
    public function __construct(
        public string $schemaVersion,
        public AgentResultStatus $status,
        public string $summary,
        public ?HumanRequestProposal $humanRequest,
        public array $findings,
        public array $criterionCoverage,
        public ?InstructionPatch $instructionPatch,
        public array $decisions = [],
        public array $changedPaths = [],
        public array $openManualGates = [],
        public ?ImplementationSummary $implementationSummary = null,
        public array $instructionRecommendations = [],
        public array $findingStatuses = [],
        public ?FindingVerificationResult $findingVerification = null,
    ) {}
}
