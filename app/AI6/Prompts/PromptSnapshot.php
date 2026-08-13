<?php

namespace App\AI6\Prompts;

use JsonSerializable;

final readonly class PromptSnapshot implements JsonSerializable
{
    /**
     * @param  array<string, string|null>  $selectedProfiles
     * @param  array<string, string>  $renderedPrompts
     */
    public function __construct(
        public string $catalogVersion,
        public array $selectedProfiles,
        public array $renderedPrompts,
        public string $hash,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'catalog_version' => $this->catalogVersion,
            'selected_profiles' => $this->selectedProfiles,
            'rendered_prompts' => $this->renderedPrompts,
            'prompt_snapshot_hash' => $this->hash,
        ];
    }
}
