<?php

namespace App\AI6\Agents;

use App\AI6\Reviews\FindingCategory;
use App\AI6\Reviews\FindingOriginalDisposition;
use App\AI6\Reviews\FindingReviewStatus;
use App\AI6\Reviews\FindingSeverity;

/** The existing provider output schema, shared without duplicating its contract. */
final readonly class AgentResultSchema
{
    /** @return array<string, mixed> */
    public function outputSchema(AgentResultContext $context): array
    {
        $string = ['type' => 'string'];
        $strings = ['type' => 'array', 'items' => $string];
        $object = static fn (array $properties): array => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
        $properties = [
            'schema_version' => ['type' => 'string', 'const' => $this->schemaVersion($context)],
            'status' => ['type' => 'string', 'enum' => array_map(
                static fn (AgentResultStatus $status): string => $status->value,
                AgentResultStatus::allowedFor($context->role),
            )],
            'summary' => $string,
            'prompt_snapshot_hash' => ['type' => 'string', 'const' => $context->promptSnapshot->hash],
            'instruction_snapshot_hash' => ['type' => 'string', 'const' => $context->instructionSnapshot->hash],
            'provider_runtime_profile_hash' => ['type' => 'string', 'const' => $context->runtimeProfile->hash],
            'human_request' => [
                'type' => ['object', 'null'],
                'properties' => [
                    'kind' => $string, 'title' => $string, 'message' => $string, 'why_needed' => $string,
                    'response_mode' => $string,
                    'options' => ['type' => 'array', 'items' => $object(['key' => $string, 'label' => $string])],
                    'recommended_option' => ['type' => ['string', 'null']],
                    'affected_paths' => $strings,
                    'criterion_refs' => $strings,
                ],
                'required' => ['kind', 'title', 'message', 'why_needed', 'response_mode', 'options', 'recommended_option', 'affected_paths', 'criterion_refs'],
                'additionalProperties' => false,
            ],
        ];
        $findingStatuses = ['type' => 'array', 'items' => $object([
            'finding_id' => ['type' => 'string', 'enum' => $context->expectedFindingIds],
            'status' => ['type' => 'string', 'enum' => array_column(FindingReviewStatus::cases(), 'value')],
            'evidence' => $string,
        ])];
        if ($context->role === AgentRole::IMPLEMENTATION) {
            $properties['decisions'] = ['type' => 'array', 'items' => $object(['key' => $string, 'title' => $string, 'rationale' => $string])];
            $properties['changed_paths'] = $strings;
            $properties['open_manual_gates'] = $strings;
            $properties['implementation_summary'] = $object([
                'changed_components' => $strings, 'decisions' => $strings, 'assumptions' => $strings,
                'deviations' => $strings, 'known_limits' => $strings, 'tests' => $strings, 'review_focus' => $strings,
            ]);
            if ($context->expectedFindingIds !== []) {
                $properties['finding_statuses'] = $findingStatuses;
            }
            if ($context->instructionUpdate) {
                $properties['instruction_patch'] = [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'schema_version' => ['type' => 'string', 'const' => 'ai6.instruction-patch.v1'],
                        'path' => $string,
                        'expected_blob_sha' => ['type' => ['string', 'null']],
                        'format' => ['type' => 'string', 'const' => 'utf8_file_replacement_v1'],
                        'content_base64' => $string,
                        'content_length' => ['type' => 'integer'],
                        'content_sha256' => $string,
                    ],
                    'required' => ['schema_version', 'path', 'expected_blob_sha', 'format', 'content_base64', 'content_length', 'content_sha256'],
                    'additionalProperties' => false,
                ];
            }
        } else {
            $properties['findings'] = ['type' => 'array', 'items' => $object([
                'local_id' => $string,
                'severity' => ['type' => 'string', 'enum' => array_column(FindingSeverity::cases(), 'value')],
                'disposition' => ['type' => 'string', 'enum' => array_column(FindingOriginalDisposition::cases(), 'value')],
                'category' => ['type' => 'string', 'enum' => array_column(FindingCategory::cases(), 'value')],
                'file' => $string, 'line' => ['type' => 'integer', 'minimum' => 1], 'title' => $string, 'evidence' => $string,
                'expected_result' => $string,
                'criterion_refs' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $context->criterionRefs]],
            ])];
            $properties['criterion_coverage'] = ['type' => 'array', 'items' => $object([
                'criterion_id' => ['type' => 'string', 'enum' => $context->criterionRefs],
                'status' => $string,
                'evidence' => $string,
            ])];
            $properties['instruction_recommendations'] = ['type' => 'array', 'items' => $object([
                'title' => $string, 'recommendation' => $string, 'reason' => $string,
            ])];
            if ($context->expectedFindingIds !== []) {
                $properties['finding_statuses'] = $findingStatuses;
            }
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $this->schemaVersion($context),
            ...$object($properties),
        ];
    }

    private function schemaVersion(AgentResultContext $context): string
    {
        return match ($context->role) {
            AgentRole::IMPLEMENTATION => 'ai6.agent.v1',
            AgentRole::FINDING_VERIFICATION => 'ai6.finding-verification.v1',
            default => 'ai6.quality-review.v1',
        };
    }
}
