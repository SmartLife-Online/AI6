<?php

namespace App\AI6\Reviews;

use JsonSerializable;

final readonly class ReviewerSlot implements JsonSerializable
{
    /** @param non-empty-list<string> $selectionReasons */
    public function __construct(
        public string $id,
        public string $profileId,
        public string $providerProfile,
        public string $model,
        public string $effort,
        public string $promptProfileId,
        public array $selectionReasons,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->profileId,
            'provider_profile' => $this->providerProfile,
            'model' => $this->model,
            'effort' => $this->effort,
            'prompt_profile_id' => $this->promptProfileId,
            'selection_reasons' => $this->selectionReasons,
        ];
    }

    public function duplicateKey(): string
    {
        return implode("\0", [$this->profileId, $this->model, $this->effort, $this->promptProfileId]);
    }
}
