<?php

namespace Tests\Unit\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Reviews\ReviewerSlot;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunType;
use App\AI6\Shared\Config\StrictEnumParser;
use Illuminate\Support\Str;
use Tests\TestCase;

/** TC-06: the one reviewer-independence invariant of AI6-033 lives in ApprovalSelection. */
final class ApprovalSelectionIndependenceTest extends TestCase
{
    public function test_a_codex_implementer_with_a_codex_reviewer_is_refused_by_name_in_an_implementation_run(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ein Reviewer-Slot darf im Implementierungslauf nicht das Providerprofil des Implementierungsslots verwenden.');
        new ApprovalSelection(
            $this->registry()->resolve('codex-gpt-5.6-terra', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'medium'),
            [$this->slot('fake', 'fake', 'fake-model', 'high'), $this->slot('codex-gpt-5.6-terra', 'codex_cli', 'gpt-5.3-codex', 'high')],
            $this->limits(),
            null,
            'manual',
        );
    }

    public function test_review_only_runs_other_implementers_and_the_fake_pair_stay_valid(): void
    {
        $registry = $this->registry();
        $codexReviewer = $this->slot('codex-gpt-5.6-terra', 'codex_cli', 'gpt-5.3-codex', 'high');

        $reviewOnly = new ApprovalSelection(
            $registry->resolve('codex-gpt-5.6-terra', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'medium'),
            [$codexReviewer],
            $this->limits(),
            null,
            'manual',
            RunType::REVIEW_ONLY,
            '{"kind":"managed_branch","ref":"refs/heads/main"}',
            ReviewOnlyCompletionMode::MANUAL,
        );
        self::assertSame(RunType::REVIEW_ONLY, $reviewOnly->runType);
        self::assertSame('codex_cli', $reviewOnly->reviewers[0]->providerProfile);

        $otherImplementer = new ApprovalSelection(
            $registry->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            [$this->slot('fake', 'fake', 'fake-model', 'high'), $codexReviewer],
            $this->limits(),
            null,
            'manual',
        );
        self::assertSame(['fake', 'codex_cli'], array_map(static fn (ReviewerSlot $slot): string => $slot->providerProfile, $otherImplementer->reviewers));

        $fakePair = new ApprovalSelection(
            $registry->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            [$this->slot('fake', 'fake', 'fake-model', 'high')],
            $this->limits(),
            null,
            'manual',
        );
        self::assertSame('fake', $fakePair->implementation->profile->providerProfileAlias);
        self::assertSame('fake', $fakePair->reviewers[0]->providerProfile);
    }

    private function registry(): AgentProfileRegistry
    {
        $profiles = config('ai6.agent_profiles');
        $profiles['codex-gpt-5.6-terra']['capability_status'] = 'available';

        return AgentProfileRegistry::fromArray($profiles, new StrictEnumParser);
    }

    private function slot(string $profileId, string $provider, string $model, string $effort): ReviewerSlot
    {
        return new ReviewerSlot((string) Str::uuid(), $profileId, $provider, $model, $effort, 'tests', ['server_profile_registered']);
    }

    private function limits(): ApprovalLimits
    {
        return ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class));
    }
}
