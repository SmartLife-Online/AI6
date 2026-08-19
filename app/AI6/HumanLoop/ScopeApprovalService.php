<?php

namespace App\AI6\HumanLoop;

use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Git\CanonicalJson;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\ProjectConfiguration;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\InstructionPathPolicy;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\ScopeCategorizer;
use App\AI6\Runs\ScopeCategory;
use App\AI6\Runs\ScopePathLimitExceeded;
use App\AI6\Runs\WaitReason;

/**
 * Routes one changed path outside the run's initial scope through the
 * deterministic Auto-Allow policy, or — when it needs a human decision —
 * opens exactly one scope_approval request for it through the existing
 * HumanLoop naht (TKT-007, AC-01…AC-04).
 */
final readonly class ScopeApprovalService
{
    public function __construct(
        private HumanRequestService $humanRequests,
        private RunOrchestrator $orchestrator,
        private ScopeCategorizer $categorizer,
        private RunLimitPolicy $limits,
        private CanonicalJson $canonicalJson,
        private InstructionPathPolicy $instructionPaths,
    ) {}

    /**
     * @return HumanRequest|null null when the path was auto-allowed without any
     *                           decision, or the opened request otherwise
     *                           (either a scope_approval or, once the
     *                           freigegebene max_added_scope_paths is
     *                           exhausted, a resource_limit request).
     */
    public function requestOrAutoAllow(
        Run $run,
        ProjectConfiguration $configuration,
        string $path,
        bool $isDeletion,
        string $agentSlotId,
        string $boundStepKey,
    ): ?HumanRequest {
        // An instruction path never joins the effective scope of a running run
        // — not even through a human scope decision. The separate contract
        // change path with a new approval and a new run is the only way an
        // instruction change becomes effective (AC-12).
        if ($this->instructionPaths->isInstructionPath($path)) {
            throw new HumanRequestRejected(
                'instruction_scope_extension_forbidden',
                'An instruction path cannot join the effective scope of a running run.',
            );
        }

        $maxAddedScopePaths = $this->limits->effective($run)['max_added_scope_paths'];
        $category = $this->categorizer->categorize($path, $isDeletion, $configuration);

        // The third track of plan §8.2: a path that is neither auto-allowed nor
        // sensible follows the trusted project default `scope.unlisted_paths`.
        // With its default `auto_allow` the necessary extension is the normal
        // case and does not block the run; `require_approval` keeps the strict
        // variant available. Both values come from the freigegebene project
        // configuration only (TKT-007).
        $autoAllow = $category === ScopeCategory::AUTO_ALLOW
            || ($category === ScopeCategory::UNDETERMINED && $configuration->unlistedPaths() === 'auto_allow');

        if ($autoAllow) {
            try {
                $this->orchestrator->applyScopeDecision(
                    $run,
                    $path,
                    true,
                    null,
                    $maxAddedScopePaths,
                    $this->canonicalJson,
                    reason: $category === ScopeCategory::AUTO_ALLOW ? 'auto_allow' : 'unlisted_auto_allow',
                );

                return null;
            } catch (ScopePathLimitExceeded $exceeded) {
                return $this->openResourceLimitRequest($run, $agentSlotId, $boundStepKey, $exceeded);
            }
        }

        if ($run->added_scope_paths_count >= $maxAddedScopePaths) {
            return $this->openResourceLimitRequest(
                $run,
                $agentSlotId,
                $boundStepKey,
                new ScopePathLimitExceeded($run->added_scope_paths_count + 1, $maxAddedScopePaths),
            );
        }

        $proposal = new HumanRequestProposal(
            'scope_approval',
            'Scope-Erweiterung',
            'Der Pfad "'.$path.'" liegt außerhalb des freigegebenen Ticket-Scopes.',
            'Die Umsetzung benötigt eine Entscheidung über diesen zusätzlichen Pfad.',
            'approve_reject',
            [new HumanRequestOption('approve', 'Genehmigen'), new HumanRequestOption('reject', 'Ablehnen')],
            null,
            [$path],
            [],
        );

        return $this->humanRequests->open($run, $proposal, $agentSlotId, $boundStepKey, WaitReason::SCOPE_APPROVAL);
    }

    private function openResourceLimitRequest(
        Run $run,
        string $agentSlotId,
        string $boundStepKey,
        ScopePathLimitExceeded $exceeded,
    ): HumanRequest {
        $proposal = new HumanRequestProposal(
            'resource_limit',
            'Scope-Limit erreicht',
            'Das freigegebene Limit für zusätzliche Scope-Pfade ist erreicht.',
            'Ohne Entscheidung kann kein weiterer Pfad in den wirksamen Scope aufgenommen werden.',
            'select',
            [new HumanRequestOption('reduce', 'Reduzieren'), new HumanRequestOption('increase', 'Erhöhen')],
            'reduce',
            [],
            [],
        );

        return $this->humanRequests->open(
            $run,
            $proposal,
            $agentSlotId,
            $boundStepKey,
            WaitReason::RESOURCE_LIMIT,
            new ImportLimitResult(ImportLimit::MAX_ADDED_SCOPE_PATHS, $exceeded->observed, $exceeded->maximum),
        );
    }
}
