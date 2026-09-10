<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentSelection;
use App\AI6\Reviews\ReviewerSlot;
use App\AI6\Reviews\VerifierCandidate;
use JsonSerializable;

final readonly class ApprovalSelection implements JsonSerializable
{
    /**
     * @param  non-empty-list<ReviewerSlot>  $reviewers
     * @param  list<VerifierCandidate>  $verifierCandidates
     */
    public function __construct(
        public AgentSelection $implementation,
        public array $reviewers,
        public ApprovalLimits $limits,
        public ?int $attentionUserId,
        public string $pushMode,
        public RunType $runType = RunType::IMPLEMENTATION,
        public ?string $reviewSubjectReference = null,
        public ?ReviewOnlyCompletionMode $completionMode = null,
        public array $verifierCandidates = [],
    ) {
        if (! in_array($pushMode, ['manual', 'automatic_after_gates'], true)) {
            throw new \InvalidArgumentException('Der Pushmodus ist ungültig.');
        }
        if ($runType === RunType::REVIEW_ONLY && ($reviewSubjectReference === null || ! $completionMode instanceof ReviewOnlyCompletionMode)) {
            throw new \InvalidArgumentException('Die Review-only-Bindung ist unvollständig.');
        }
        if ($runType === RunType::IMPLEMENTATION && ($reviewSubjectReference !== null || $completionMode !== null)) {
            throw new \InvalidArgumentException('Eine Implementierungs-Approval darf keine Review-only-Bindung enthalten.');
        }
        // Reviewer independence (AGT-010, AI6-033): in an implementation run no
        // reviewer slot uses the provider profile of the implementation slot.
        // Review-only runs are free, and the credential- and process-free test
        // provider `fake` is the one exempt test provider.
        if ($runType === RunType::IMPLEMENTATION && $implementation->profile->providerProfileAlias !== 'fake') {
            foreach ($reviewers as $slot) {
                if ($slot->providerProfile === $implementation->profile->providerProfileAlias) {
                    throw new \InvalidArgumentException('Ein Reviewer-Slot darf im Implementierungslauf nicht das Providerprofil des Implementierungsslots verwenden.');
                }
            }
        }
        if ($reviewSubjectReference !== null && (strlen($reviewSubjectReference) > 2048
            || $reviewSubjectReference === ''
            || preg_match('//u', $reviewSubjectReference) !== 1
            || preg_match('/\A[{},:"0-9A-Za-z._\/-]+\z/D', $reviewSubjectReference) !== 1)) {
            throw new \InvalidArgumentException('Die Reviewgegenstandsreferenz ist ungültig.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $serialized = [
            'implementation' => [
                'profile_id' => $this->implementation->profile->id,
                'provider_profile' => $this->implementation->profile->providerProfileAlias,
                'model' => $this->implementation->model,
                'effort' => $this->implementation->effort,
                'runtime_profile_id' => $this->implementation->profile->runtimeProfileId,
            ],
            'reviewers' => array_map(static fn (ReviewerSlot $slot): array => $slot->jsonSerialize(), $this->reviewers),
            'verifier_candidates' => array_map(static fn (VerifierCandidate $candidate): array => $candidate->jsonSerialize(), $this->verifierCandidates),
            'limits' => $this->limits->jsonSerialize(),
            'attention_user_id' => $this->attentionUserId,
            'push_mode' => $this->pushMode,
        ];
        if ($this->runType === RunType::REVIEW_ONLY) {
            $serialized['run_type'] = $this->runType->value;
            $serialized['review_subject_reference'] = $this->reviewSubjectReference;
            $serialized['completion_mode'] = $this->completionMode?->value;
        }

        return $serialized;
    }
}
