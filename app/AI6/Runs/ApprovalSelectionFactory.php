<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Reviews\ReviewerSlotFactory;

final readonly class ApprovalSelectionFactory
{
    public function __construct(
        private AgentProfileRegistry $profiles,
        private ReviewerSlotFactory $reviewers,
        private AgentInputLimits $inputLimits,
    ) {}

    /** @param array<string, mixed> $value */
    public function fromArray(array $value): ApprovalSelection
    {
        $implementation = $value['implementation'] ?? null;
        $reviewers = $value['reviewers'] ?? null;
        $limits = $value['limits'] ?? null;
        if (! is_array($implementation) || ! is_array($reviewers) || ! is_array($limits)) {
            throw new \InvalidArgumentException('Die Approval-Auswahl ist unvollständig.');
        }
        $reviewerInputs = array_map(static fn (array $slot): array => [
            'id' => $slot['id'] ?? null,
            'profile' => $slot['profile_id'] ?? null,
            'model' => $slot['model'] ?? null,
            'effort' => $slot['effort'] ?? null,
            'prompt_profile' => $slot['prompt_profile_id'] ?? null,
        ], $reviewers);

        return new ApprovalSelection(
            $this->profiles->resolve(
                (string) ($implementation['profile_id'] ?? ''),
                AgentRole::IMPLEMENTATION,
                (string) ($implementation['model'] ?? ''),
                (string) ($implementation['effort'] ?? ''),
            ),
            $this->reviewers->fromArray($reviewerInputs),
            ApprovalLimits::fromConfiguredValues($limits, $this->inputLimits),
            is_int($value['attention_user_id'] ?? null) ? $value['attention_user_id'] : null,
            is_string($value['push_mode'] ?? null) ? $value['push_mode'] : '',
            RunType::tryFrom(is_string($value['run_type'] ?? null) ? $value['run_type'] : '') ?? RunType::IMPLEMENTATION,
            is_string($value['review_subject_reference'] ?? null) ? $value['review_subject_reference'] : null,
            ReviewOnlyCompletionMode::tryFrom(is_string($value['completion_mode'] ?? null) ? $value['completion_mode'] : ''),
        );
    }
}
