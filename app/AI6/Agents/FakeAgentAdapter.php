<?php

namespace App\AI6\Agents;

use JsonException;

final class FakeAgentAdapter implements AgentAdapter
{
    public function __construct(private readonly AgentScenario $scenario = AgentScenario::SUCCESS) {}

    public function result(AgentResultContext $context): string
    {
        $scenario = $this->scenario;
        if ($scenario === AgentScenario::INVALID_JSON) {
            return '{"schema_version":';
        }

        $document = $this->baseDocument($context, $this->status($scenario, $context));
        if (in_array($scenario, [AgentScenario::FINDINGS, AgentScenario::SECURITY_FINDINGS], true)) {
            $document['findings'] = [$this->finding($context)];
            $document['criterion_coverage'] = $this->coverage($context);
        }
        if ($scenario === AgentScenario::HUMAN_REQUEST) {
            $document['human_request'] = [
                'kind' => 'clarification',
                'title' => 'Rückfrage',
                'message' => 'Eine Entscheidung wird benötigt.',
                'why_needed' => 'Die gebundene Umsetzung benötigt eine Auswahl.',
                'response_mode' => 'select',
                'options' => [['key' => 'a', 'label' => 'Option A'], ['key' => 'b', 'label' => 'Option B']],
                'recommended_option' => 'a',
                'affected_paths' => ['app/Example.php'],
                'criterion_refs' => $context->criterionRefs,
            ];
        }
        if (in_array($context->role, [AgentRole::QUALITY_REVIEW, AgentRole::SECURITY_REVIEW], true)
            && ! array_key_exists('criterion_coverage', $document)) {
            $document['criterion_coverage'] = $this->coverage($context);
        }

        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new \LogicException('The deterministic fake document could not be encoded.');
        }
    }

    /** @return array<string, mixed> */
    private function baseDocument(AgentResultContext $context, AgentResultStatus $status): array
    {
        return [
            'schema_version' => $context->role === AgentRole::IMPLEMENTATION ? 'ai6.agent.v1' : 'ai6.quality-review.v1',
            'status' => $status->value,
            'summary' => 'Deterministisches Fake-Ergebnis.',
            'prompt_snapshot_hash' => $context->promptSnapshot->hash,
            'instruction_snapshot_hash' => $context->instructionSnapshot->hash,
            'provider_runtime_profile_hash' => $context->runtimeProfile->hash,
        ];
    }

    private function status(AgentScenario $scenario, AgentResultContext $context): AgentResultStatus
    {
        return match ($scenario) {
            AgentScenario::SUCCESS => match ($context->role) {
                AgentRole::IMPLEMENTATION => AgentResultStatus::COMPLETED,
                AgentRole::QUALITY_REVIEW => AgentResultStatus::NOTHING_TO_FIX,
                AgentRole::FINDING_VERIFICATION, AgentRole::SECURITY_REVIEW => AgentResultStatus::CLEAR,
            },
            AgentScenario::NO_CHANGE_REQUIRED, AgentScenario::NO_CHANGE_WITH_DIFF => AgentResultStatus::NO_CHANGE_REQUIRED,
            AgentScenario::HUMAN_REQUEST => AgentResultStatus::NEEDS_HUMAN,
            AgentScenario::FINDINGS => AgentResultStatus::FINDINGS_TO_FIX,
            AgentScenario::PROVIDER_ERROR => AgentResultStatus::FAILED,
            AgentScenario::SECURITY_FINDINGS => AgentResultStatus::SECURITY_FINDINGS,
            AgentScenario::INVALID_JSON => throw new \LogicException('Invalid JSON has no typed status.'),
        };
    }

    /** @return array<string, mixed> */
    private function finding(AgentResultContext $context): array
    {
        return [
            'local_id' => 'finding-1',
            'severity' => 'must_fix',
            'disposition' => 'open',
            'category' => 'contract',
            'file' => 'app/Example.php',
            'line' => 1,
            'title' => 'Deterministischer Befund',
            'evidence' => 'Das erwartete Ergebnis fehlt.',
            'expected_result' => 'Der Vertrag ist erfüllt.',
            'criterion_refs' => $context->criterionRefs,
        ];
    }

    /** @return list<array{criterion_id: string, status: string, evidence: string}> */
    private function coverage(AgentResultContext $context): array
    {
        return array_map(
            static fn (string $criterion): array => ['criterion_id' => $criterion, 'status' => 'satisfied', 'evidence' => 'Deterministischer Nachweis.'],
            $context->criterionRefs,
        );
    }
}
