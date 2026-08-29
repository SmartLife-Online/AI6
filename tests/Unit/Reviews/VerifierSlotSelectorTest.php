<?php

namespace Tests\Unit\Reviews;

use App\AI6\Reviews\VerifierSlotSelector;
use PHPUnit\Framework\TestCase;

final class VerifierSlotSelectorTest extends TestCase
{
    public function test_selection_excludes_source_provider_and_implementation_profile(): void
    {
        $pool = [
            $this->candidate('source', 'source-profile', 'grok_cli'),
            $this->candidate('same-provider-model', 'other-grok', 'grok_cli'),
            $this->candidate('implementation', 'implementation-profile', 'codex_cli'),
            $this->candidate('independent', 'copilot-profile', 'github_copilot_cli'),
        ];

        $selected = (new VerifierSlotSelector)->select($pool, ['grok_cli'], ['implementation-profile']);

        self::assertSame('independent', $selected->id);
        self::assertSame('github_copilot_cli', $selected->providerProfile);
    }

    public function test_no_independent_candidate_returns_no_self_verification(): void
    {
        $selected = (new VerifierSlotSelector)->select([
            $this->candidate('source', 'source-profile', 'grok_cli'),
            $this->candidate('implementation', 'implementation-profile', 'codex_cli'),
        ], ['grok_cli'], ['implementation-profile']);

        self::assertNull($selected);
    }

    /** @return array<string, mixed> */
    private function candidate(string $id, string $profile, string $provider): array
    {
        return [
            'id' => $id,
            'profile_id' => $profile,
            'provider_profile' => $provider,
            'model' => 'model',
            'effort' => 'medium',
            'prompt_profile_id' => 'finding_verification',
        ];
    }
}
