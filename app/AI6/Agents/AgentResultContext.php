<?php

namespace App\AI6\Agents;

use App\AI6\Git\CanonicalJson;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Shared\Json\RestrictedJsonDecoder;

final readonly class AgentResultContext
{
    /**
     * @param  list<string>  $criterionRefs
     * @param  list<string>  $initialScope
     * @param  array<string, string|null>  $expectedInstructionBlobs
     * @param  list<string>  $expectedFindingIds
     * @param  list<string>  $unreachablePaths
     * @param  list<string>  $expectedFindingGroups
     * @param  string  $model  The run slot's approved model; bound by AgentExecutionRunner before staging, '' until then.
     * @param  string  $effort  The run slot's approved effort; bound together with the model.
     */
    public function __construct(
        public AgentRole $role,
        public PromptSnapshot $promptSnapshot,
        public InstructionSnapshot $instructionSnapshot,
        public ProviderRuntimeProfile $runtimeProfile,
        public array $criterionRefs,
        public string $actualDiff,
        public bool $instructionUpdate = false,
        public array $initialScope = [],
        public array $expectedInstructionBlobs = [],
        public string $slotId = '',
        public int $attempt = 1,
        public array $expectedFindingIds = [],
        public array $expectedFindingGroups = [],
        public array $unreachablePaths = [],
        public string $model = '',
        public string $effort = '',
    ) {}

    /**
     * Bind the approved selection of the run slot (AGT-002). A context that
     * already carries a selection must carry exactly this one: the sealed
     * turn.json and the staged binding.json may never disagree on it.
     */
    public function withSelection(string $model, string $effort): self
    {
        if (($this->model !== '' && $this->model !== $model) || ($this->effort !== '' && $this->effort !== $effort)) {
            throw new AgentExecutionException('agent_selection_binding_invalid');
        }

        return new self(
            $this->role, $this->promptSnapshot, $this->instructionSnapshot, $this->runtimeProfile,
            $this->criterionRefs, $this->actualDiff, $this->instructionUpdate, $this->initialScope,
            $this->expectedInstructionBlobs, $this->slotId, $this->attempt, $this->expectedFindingIds,
            $this->expectedFindingGroups, $this->unreachablePaths, $model, $effort,
        );
    }

    public function toJson(): string
    {
        return app(CanonicalJson::class)->normalizeAndEncode([
            'schema' => 'ai6.agent-turn-context.v1',
            'role' => $this->role->value,
            'prompt_snapshot' => $this->promptSnapshot->jsonSerialize(),
            'instruction_snapshot' => $this->instructionSnapshot->jsonSerialize(),
            'runtime_profile_id' => $this->runtimeProfile->id,
            'criterion_refs' => $this->criterionRefs,
            'actual_diff' => $this->actualDiff,
            'instruction_update' => $this->instructionUpdate,
            'initial_scope' => $this->initialScope,
            'expected_instruction_blobs' => (object) $this->expectedInstructionBlobs,
            'slot_id' => $this->slotId,
            'attempt' => $this->attempt,
            'expected_finding_ids' => $this->expectedFindingIds,
            'expected_finding_groups' => $this->expectedFindingGroups,
            'unreachable_paths' => $this->unreachablePaths,
            'model' => $this->model,
            'effort' => $this->effort,
        ])."\n";
    }

    public static function fromJson(string $bytes, ProviderRuntimeProfileRegistry $profiles, string $expectedSha256): self
    {
        try {
            $document = app(RestrictedJsonDecoder::class)->decodeSealedSnapshot($bytes, $expectedSha256);
            if (($document['schema'] ?? null) !== 'ai6.agent-turn-context.v1') {
                throw new AgentExecutionException('agent_context_schema_invalid');
            }
            $prompt = $document['prompt_snapshot'];
            $instruction = $document['instruction_snapshot'];
            if (! is_array($prompt) || ! is_array($instruction) || ! is_array($instruction['entries'] ?? null)
                || ! array_is_list($instruction['entries'])) {
                throw new AgentExecutionException('agent_context_snapshot_invalid');
            }
            $entries = [];
            foreach ($instruction['entries'] as $entry) {
                if (! is_array($entry)) {
                    throw new AgentExecutionException('agent_context_instruction_invalid');
                }
                $entries[] = new InstructionSnapshotEntry(
                    self::text($entry['discovery_name']), self::text($entry['scope']),
                    self::number($entry['priority']), self::text($entry['repository_path']),
                    self::text($entry['blob_sha']), self::text($entry['effective_content']), self::strings($entry['imports']),
                );
            }
            $rendered = self::map($prompt['rendered_prompts']);
            foreach ($rendered as $value) {
                if ($value === null) {
                    throw new AgentExecutionException('agent_context_prompt_invalid');
                }
            }
            /** @var array<string, string> $rendered */
            $context = new self(
                AgentRole::from(self::text($document['role'])),
                new PromptSnapshot(self::text($prompt['catalog_version']), self::map($prompt['selected_profiles']),
                    $rendered, self::text($prompt['prompt_snapshot_hash'])),
                new InstructionSnapshot(self::text($instruction['provider_profile_alias']), $entries,
                    self::text($instruction['instruction_snapshot_hash'])),
                $profiles->get(self::text($document['runtime_profile_id'])),
                self::strings($document['criterion_refs']), self::text($document['actual_diff']),
                self::flag($document['instruction_update']), self::strings($document['initial_scope']),
                self::map($document['expected_instruction_blobs']), self::text($document['slot_id']),
                self::number($document['attempt']), self::strings($document['expected_finding_ids']),
                self::strings($document['expected_finding_groups']), self::strings($document['unreachable_paths']),
                self::text($document['model']), self::text($document['effort']),
            );
            // Round-tripping through the existing snapshot value objects rejects
            // missing/extra fields and changed entry content hashes as well.
            if ($context->toJson() !== $bytes || $context->attempt < 1 || $context->attempt > 1000) {
                throw new AgentExecutionException('agent_context_shape_invalid');
            }

            return $context;
        } catch (AgentExecutionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AgentExecutionException('agent_context_invalid');
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new AgentExecutionException('agent_context_type_invalid');
    }

    private static function number(mixed $value): int
    {
        return is_int($value) ? $value : throw new AgentExecutionException('agent_context_type_invalid');
    }

    private static function flag(mixed $value): bool
    {
        return is_bool($value) ? $value : throw new AgentExecutionException('agent_context_type_invalid');
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new AgentExecutionException('agent_context_list_invalid');
        }
        foreach ($value as $item) {
            self::text($item);
        }

        /** @var list<string> $value */
        return $value;
    }

    /** @return array<string, string|null> */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            throw new AgentExecutionException('agent_context_map_invalid');
        }
        foreach ($value as $key => $item) {
            if (! is_string($key) || ($item !== null && ! is_string($item))) {
                throw new AgentExecutionException('agent_context_map_invalid');
            }
        }

        return $value;
    }
}
