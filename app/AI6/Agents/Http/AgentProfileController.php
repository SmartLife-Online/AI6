<?php

namespace App\AI6\Agents\Http;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Prompts\PromptCatalog;
use Illuminate\Contracts\View\View;

final readonly class AgentProfileController
{
    public function __construct(
        private AgentProfileRegistry $profiles,
        private ProviderRuntimeProfileRegistry $runtimeProfiles,
        private PromptCatalog $catalog,
    ) {}

    public function __invoke(): View
    {
        $diagnoses = [];
        foreach ($this->profiles->configured() as $profile) {
            $diagnoses[$profile->id] = app(ProviderCapabilityReport::class)->diagnosis($profile);
        }

        return view('agents.profiles', [
            'profiles' => $this->profiles->configured(),
            'runtimeProfiles' => $this->runtimeProfiles,
            'catalogVersion' => $this->catalog->version,
            'diagnoses' => $diagnoses,
            'diagnosticReasons' => ProviderCapabilityReport::REASONS,
        ]);
    }
}
