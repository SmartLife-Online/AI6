<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentProfile;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\InstructionSnapshotResolver;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\CanonicalJson;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Tickets\TicketV1Parser;

final readonly class ApprovalSnapshotFactory
{
    public function __construct(
        private EffectiveProjectConfiguration $configurations,
        private PromptRenderer $prompts,
        private InstructionSnapshotResolver $instructions,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
        private AgentProfileRegistry $agentProfiles,
        private InstructionCandidateSource $instructionCandidates,
        private SecurityPolicy $securityPolicy,
        private TicketV1Parser $tickets,
        private CanonicalJson $canonicalJson,
        private PromptCatalog $promptCatalog,
    ) {}

    public function create(Project $project, TicketReadModel $readModel, ApprovalSelection $selection, string $operationId): ApprovalSnapshot
    {
        $binding = $this->configurations->for($project);
        $document = $this->tickets->parse($readModel->redacted_content);
        $context = new RedactionContext((string) $project->getKey(), $operationId, 'ticket-approval-snapshot');
        $promptContext = 'Ticketvertrag '.$readModel->ticket_contract_sha256.' ('.$readModel->relative_path.')';
        $promptRequests = [
            new PromptRenderRequest('implementation', new PromptVariables(['context' => $promptContext])),
            new PromptRenderRequest('fix', new PromptVariables(['context' => $promptContext])),
        ];
        $firstReviewer = $selection->reviewers[0];
        $promptRequests[] = new PromptRenderRequest('quality_review', new PromptVariables(['context' => $promptContext]), $firstReviewer->promptProfileId);
        $prompt = $this->prompts->snapshot($promptRequests, $context);
        $promptSnapshot = $prompt->jsonSerialize();
        $reviewProfileSnapshots = [];
        foreach ($selection->reviewers as $reviewer) {
            if (isset($reviewProfileSnapshots[$reviewer->promptProfileId])) {
                continue;
            }
            $reviewProfile = $this->promptCatalog->reviewProfile($reviewer->promptProfileId);
            $reviewEntry = $this->promptCatalog->entry('quality_review');
            $reviewProfileSnapshots[$reviewer->promptProfileId] = $this->prompts->snapshot([
                new PromptRenderRequest('quality_review', new PromptVariables(['context' => $promptContext]), $reviewer->promptProfileId),
            ], $context)->jsonSerialize() + [
                'entry_version' => $reviewEntry->version,
                'template_sha256' => hash('sha256', $reviewEntry->template),
                'review_profile_version' => $reviewProfile->version,
            ];
        }
        ksort($reviewProfileSnapshots, SORT_STRING);
        $promptSnapshot['review_profile_snapshots'] = $reviewProfileSnapshots;
        $fixEntry = $this->promptCatalog->entry('fix');
        $promptSnapshot['fix_prompt_binding'] = [
            'entry_version' => $fixEntry->version,
            'template_sha256' => hash('sha256', $fixEntry->template),
        ];

        $providers = [$selection->implementation->profile->providerProfileAlias];
        foreach ($selection->reviewers as $reviewer) {
            $providers[] = $reviewer->providerProfile;
        }
        $providers = array_values(array_unique($providers));
        sort($providers, SORT_STRING);
        $instructionSnapshots = [];
        foreach ($providers as $provider) {
            $candidates = $this->instructionCandidates->collect($project, $provider, $document->files, $context);
            $instructionSnapshots[$provider] = $this->instructions->resolve($provider, $candidates, $context)->jsonSerialize();
        }

        $runtimeIds = [$selection->implementation->profile->runtimeProfileId];
        foreach ($selection->reviewers as $reviewer) {
            $profileId = $selection->implementation->profile->id === $reviewer->profileId
                ? $selection->implementation->profile->runtimeProfileId
                : null;
            if ($profileId === null) {
                // The selected slot already carries a server-validated profile id; resolve its runtime below.
                $profileId = $this->agentProfiles->get($reviewer->profileId)->runtimeProfileId;
            }
            $runtimeIds[] = $profileId;
        }
        $runtimeIds = array_values(array_unique($runtimeIds));
        sort($runtimeIds, SORT_STRING);
        $runtime = [];
        foreach ($runtimeIds as $id) {
            $runtime[$id] = $this->runtimeProfiles->get($id)->jsonSerialize();
        }

        $scope = [
            'ticket_files' => $document->files,
            'project_scope' => $binding->configuration->values['scope'],
        ];
        $config = [
            'source' => $binding->source->value,
            'values' => $binding->configuration->values,
            'blob_sha' => $binding->blobSha,
            'snapshot_id' => $binding->snapshotId,
            'control_generation' => $binding->controlGeneration,
        ];
        $agents = $selection->jsonSerialize();
        $implementationProfile = $selection->implementation->profile;
        $agents['implementation'] += $this->profileSnapshot($implementationProfile);
        foreach ($agents['reviewers'] as $index => $reviewer) {
            $profileId = $reviewer['profile_id'] ?? null;
            if (is_string($profileId)) {
                $agents['reviewers'][$index] += $this->profileSnapshot($this->agentProfiles->get($profileId));
            }
        }
        $scopeHash = $this->hash('AI6-APPROVAL-SCOPE-V1', $scope);
        $promptHash = $this->hash('AI6-APPROVAL-PROMPTS-V1', $promptSnapshot);
        $instructionHash = $this->hash('AI6-APPROVAL-INSTRUCTIONS-V1', $instructionSnapshots);
        $runtimeHash = $this->hash('AI6-APPROVAL-RUNTIME-PROFILES-V1', $runtime);
        $agentHash = $this->hash('AI6-APPROVAL-AGENT-PROFILES-V1', $agents);
        $aggregate = [
            'config_hash' => $binding->configHash,
            'scope_hash' => $scopeHash,
            'prompt_hash' => $promptHash,
            'instruction_hash' => $instructionHash,
            'runtime_profile_hash' => $runtimeHash,
            'agent_profile_hash' => $agentHash,
            'security_policy_hash' => $this->securityPolicy->hash(),
        ];

        return new ApprovalSnapshot(
            $config,
            $binding->configHash,
            $scope,
            $scopeHash,
            $promptSnapshot,
            $promptHash,
            $instructionSnapshots,
            $instructionHash,
            $runtime,
            $runtimeHash,
            $agents,
            $agentHash,
            $this->securityPolicy->hash(),
            $this->hash('AI6-APPROVAL-SNAPSHOT-V1', $aggregate),
        );
    }

    /** @param array<string, mixed> $value */
    private function hash(string $domain, array $value): string
    {
        return hash('sha256', $domain."\0".$this->canonicalJson->normalizeAndEncode($value));
    }

    /** @return array<string, mixed> */
    private function profileSnapshot(AgentProfile $profile): array
    {
        return [
            'adapter_id' => $profile->adapterId,
            'runtime_profile_id' => $profile->runtimeProfileId,
            'capability_status' => $profile->capabilityStatus->value,
            'allowed_models' => $profile->models,
            'allowed_efforts' => $profile->efforts,
            'allowed_roles' => array_map(static fn (AgentRole $role): string => $role->value, $profile->roles),
        ];
    }
}
