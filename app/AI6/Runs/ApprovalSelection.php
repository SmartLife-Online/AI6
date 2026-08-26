<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentSelection;
use App\AI6\Reviews\ReviewerSlot;
use JsonSerializable;

final readonly class ApprovalSelection implements JsonSerializable
{
    /** @param non-empty-list<ReviewerSlot> $reviewers */
    public function __construct(
        public AgentSelection $implementation,
        public array $reviewers,
        public ApprovalLimits $limits,
        public ?int $attentionUserId,
        public string $pushMode,
        public RunType $runType = RunType::IMPLEMENTATION,
        public ?string $reviewSubjectReference = null,
        public ?ReviewOnlyCompletionMode $completionMode = null,
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
        if ($reviewSubjectReference !== null && (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:@+\/-]{0,2047}\z/D', $reviewSubjectReference))) {
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
