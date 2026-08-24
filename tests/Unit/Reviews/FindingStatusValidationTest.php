<?php

namespace Tests\Unit\Reviews;

use App\AI6\Agents\AgentResult;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultValidationError;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Reviews\FindingReviewStatus;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\TestCase;

final class FindingStatusValidationTest extends TestCase
{
    public function test_every_presented_finding_is_classified_exactly_once_from_the_closed_set(): void
    {
        $context = $this->context();
        $valid = json_decode((new FakeAgentAdapter(AgentScenario::SUCCESS))->result($context), true, 16, JSON_THROW_ON_ERROR);
        $result = $this->validate($valid, $context);
        self::assertSame(['finding-a', 'finding-b'], array_map(static fn ($entry): string => $entry->findingId, $result->findingStatuses));
        self::assertSame(FindingReviewStatus::FIXED, $result->findingStatuses[0]->status);

        foreach (['missing', 'duplicate', 'unknown_reference', 'unknown_status'] as $case) {
            $document = $valid;
            match ($case) {
                'missing' => array_pop($document['finding_statuses']),
                'duplicate' => $document['finding_statuses'][] = $document['finding_statuses'][0],
                'unknown_reference' => $document['finding_statuses'][0]['finding_id'] = 'finding-unknown',
                'unknown_status' => $document['finding_statuses'][0]['status'] = 'provider_decides_fixed',
            };
            try {
                $this->validate($document, $context);
                self::fail('An incomplete or unknown finding status was accepted: '.$case);
            } catch (AgentResultValidationException $exception) {
                self::assertSame(AgentResultValidationError::SCHEMA, $exception->reason);
            }
        }
    }

    private function context(): AgentResultContext
    {
        $redaction = new RedactionContext('project', 'run', 'finding-status-test');
        $prompt = $this->app->make(PromptRenderer::class)->snapshot([
            new PromptRenderRequest('quality_review', new PromptVariables(['context' => 'Vollständiger Re-Review']), 'tests'),
        ], $redaction);

        return new AgentResultContext(
            AgentRole::QUALITY_REVIEW,
            $prompt,
            new InstructionSnapshot('fake', [], str_repeat('b', 64)),
            new ProviderRuntimeProfile('fake-v1', 1, [], [], [], str_repeat('c', 64)),
            [],
            '',
            expectedFindingIds: ['finding-a', 'finding-b'],
        );
    }

    /** @param array<string, mixed> $document */
    private function validate(array $document, AgentResultContext $context): AgentResult
    {
        return $this->app->make(AgentResultValidator::class)->validate(
            json_encode($document, JSON_THROW_ON_ERROR),
            $context,
            new RedactionContext('project', 'run', 'finding-status-test'),
        );
    }
}
