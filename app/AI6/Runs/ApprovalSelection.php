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
    ) {
        if (! in_array($pushMode, ['manual', 'automatic_after_gates'], true)) {
            throw new \InvalidArgumentException('Der Pushmodus ist ungültig.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
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
    }
}
