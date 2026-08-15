<?php

namespace Tests\Unit\Reviews;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentProfileSelectionError;
use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Prompts\PromptRenderingError;
use App\AI6\Prompts\PromptRenderingException;
use App\AI6\Reviews\ReviewerSlotFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class ReviewerSlotFactoryTest extends TestCase
{
    public function test_slots_are_server_resolved_stable_and_duplicate_free(): void
    {
        $factory = $this->app->make(ReviewerSlotFactory::class);
        $id = (string) Str::uuid();
        $slots = $factory->fromArray([[
            'id' => $id,
            'profile' => 'fake',
            'model' => 'fake-model',
            'effort' => 'medium',
            'prompt_profile' => 'security',
        ]]);

        self::assertSame($id, $slots[0]->id);
        self::assertSame('fake', $slots[0]->providerProfile);
        self::assertSame('security', $slots[0]->promptProfileId);
        self::assertContains('server_profile_registered', $slots[0]->selectionReasons);

        $this->expectException(InvalidArgumentException::class);
        $factory->fromArray([
            ['id' => (string) Str::uuid(), 'profile' => 'fake', 'model' => 'fake-model', 'effort' => 'medium', 'prompt_profile' => 'security'],
            ['id' => (string) Str::uuid(), 'profile' => 'fake', 'model' => 'fake-model', 'effort' => 'medium', 'prompt_profile' => 'security'],
        ]);
    }

    public function test_missing_reviewer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(ReviewerSlotFactory::class)->fromArray([]);
    }

    public function test_every_slot_value_is_resolved_against_the_server_registers(): void
    {
        $factory = $this->app->make(ReviewerSlotFactory::class);
        $base = [
            'id' => (string) Str::uuid(),
            'profile' => 'fake',
            'model' => 'fake-model',
            'effort' => 'medium',
            'prompt_profile' => 'security',
        ];

        foreach ([
            ['model' => 'unknown-model'],
            ['effort' => 'unknown-effort'],
            ['profile' => 'unknown-profile'],
        ] as $change) {
            try {
                $factory->fromArray([array_replace($base, $change, ['id' => (string) Str::uuid()])]);
                self::fail('Eine nicht registrierte Agentenprofilauswahl wurde akzeptiert.');
            } catch (AgentProfileSelectionException $exception) {
                self::assertContains($exception->reason, [
                    AgentProfileSelectionError::PROFILE_UNKNOWN,
                    AgentProfileSelectionError::COMBINATION_NOT_ALLOWED,
                ]);
            }
        }

        try {
            $factory->fromArray([array_replace($base, [
                'id' => (string) Str::uuid(),
                'prompt_profile' => 'unknown-review-profile',
            ])]);
            self::fail('Ein unbekanntes Review-Promptprofil wurde akzeptiert.');
        } catch (PromptRenderingException $exception) {
            self::assertSame(PromptRenderingError::REVIEW_PROFILE_UNKNOWN, $exception->reason);
        }

        config(['ai6.agent_profiles.implementation-only' => [
            'provider_profile' => 'fake',
            'adapter' => 'fake',
            'models' => ['fake-model'],
            'efforts' => ['medium'],
            'roles' => ['implementation'],
            'capability_status' => 'available',
            'runtime_profile' => 'fake-v1',
        ]]);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $this->app->forgetInstance(ReviewerSlotFactory::class);
        try {
            $this->app->make(ReviewerSlotFactory::class)->fromArray([array_replace($base, [
                'id' => (string) Str::uuid(),
                'profile' => 'implementation-only',
            ])]);
            self::fail('Eine für Quality Review unzulässige Rollen-Kombination wurde akzeptiert.');
        } catch (AgentProfileSelectionException $exception) {
            self::assertSame(AgentProfileSelectionError::COMBINATION_NOT_ALLOWED, $exception->reason);
        }
    }
}
