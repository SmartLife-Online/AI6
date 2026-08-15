<?php

namespace App\AI6\Reviews;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Prompts\PromptCatalog;
use InvalidArgumentException;

final readonly class ReviewerSlotFactory
{
    public function __construct(
        private AgentProfileRegistry $profiles,
        private PromptCatalog $prompts,
    ) {}

    /**
     * @param  list<array{id?: mixed, profile?: mixed, model?: mixed, effort?: mixed, prompt_profile?: mixed}>  $values
     * @return non-empty-list<ReviewerSlot>
     */
    public function fromArray(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Mindestens ein Reviewer-Slot ist erforderlich.');
        }

        $slots = [];
        $ids = [];
        $duplicates = [];
        foreach ($values as $value) {
            $id = is_string($value['id'] ?? null) ? strtolower($value['id']) : '';
            $profileId = is_string($value['profile'] ?? null) ? $value['profile'] : '';
            $model = is_string($value['model'] ?? null) ? $value['model'] : '';
            $effort = is_string($value['effort'] ?? null) ? $value['effort'] : '';
            $promptProfile = is_string($value['prompt_profile'] ?? null) ? $value['prompt_profile'] : '';
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id) !== 1 || isset($ids[$id])) {
                throw new InvalidArgumentException('Jeder Reviewer-Slot braucht eine eindeutige, stabile UUID.');
            }

            $selection = $this->profiles->resolve($profileId, AgentRole::QUALITY_REVIEW, $model, $effort);
            $profile = $this->prompts->reviewProfile($promptProfile);
            $slot = new ReviewerSlot($id, $selection->profile->id, $selection->profile->providerProfileAlias, $selection->model, $selection->effort, $profile->id, [
                'server_profile_registered',
                'quality_review_capability_available',
                'review_prompt_profile_registered',
            ]);
            if (isset($duplicates[$slot->duplicateKey()])) {
                throw new InvalidArgumentException('Inhaltlich identische Reviewer-Slots sind nicht zulässig.');
            }
            $ids[$id] = true;
            $duplicates[$slot->duplicateKey()] = true;
            $slots[] = $slot;
        }

        return $slots;
    }
}
