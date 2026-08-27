<?php

namespace App\AI6\Reviews;

use App\AI6\Git\CanonicalJson;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Shared\Redaction\RedactionContext;

final readonly class ReviewContextPackageStore
{
    public function __construct(
        private RunArtifactStore $artifacts,
        private CanonicalJson $json,
    ) {}

    /**
     * Build the redacted stage package without persisting it, so the caller can
     * apply the shared artifact limits before anything reaches the store.
     *
     * An already published package for this stage is returned as-is; a changed
     * binding invalidates it instead of silently producing a second package.
     *
     * @param  list<string>  $criterionRefs
     * @param  array<string, mixed>  $slotBindings
     * @return array{artifact: RunArtifact|null, bytes: string, metadata: array<string, string>}
     */
    public function prepare(Run $run, RunAgent $slot, int $round, array $criterionRefs, array $slotBindings, RedactionContext $context): array
    {
        $stage = 'quality_review:'.$round.':'.$slot->slot_id;
        $bindingHash = $this->bindingHash($run, $slotBindings);
        $existing = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::CONTEXT_PACKAGE->value)
            ->get()->first(static fn (RunArtifact $artifact): bool => ($artifact->redacted_metadata['stage'] ?? null) === $stage);
        if ($existing instanceof RunArtifact) {
            if (! hash_equals((string) ($existing->redacted_metadata['binding_hash'] ?? ''), $bindingHash)) {
                throw new ReviewResultParseException('context_package_binding_changed');
            }

            return ['artifact' => $existing, 'bytes' => '', 'metadata' => []];
        }
        $payload = [
            'schema' => 'ai6.context-package.v1',
            'stage' => $stage,
            'run_id' => $run->id,
            'ticket_blob_sha' => $run->ticket_blob_sha,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash,
            'workspace_tree_hash' => $slotBindings['workspace_tree_hash'] ?? null,
            'scope' => $run->effective_scope_snapshot ?? (($run->scope_snapshot ?? [])['ticket_files'] ?? []),
            'scope_hash' => $run->effective_scope_hash ?? $run->scope_hash,
            'prompt_hash' => $slotBindings['slot_prompt_hash'] ?? null,
            'instruction_hash' => $slotBindings['slot_instruction_hash'] ?? null,
            'provider_runtime_profile_hash' => $slotBindings['slot_runtime_profile_hash'] ?? null,
            'security_policy_hash' => $run->security_policy_hash,
            'provider_profile' => $slot->provider_profile,
            'model' => $slot->model,
            'effort' => $slot->effort,
            'criterion_refs' => $criterionRefs,
        ];

        return [
            'artifact' => null,
            'bytes' => $this->artifacts->encodeCanonicalJson($payload, $context),
            'metadata' => ['stage' => $stage, 'binding_hash' => $bindingHash],
        ];
    }

    /** @param array{artifact: RunArtifact|null, bytes: string, metadata: array<string, string>} $prepared */
    public function persist(Run $run, array $prepared, RedactionContext $context): RunArtifact
    {
        if ($prepared['artifact'] instanceof RunArtifact) {
            return $prepared['artifact'];
        }

        return $this->artifacts->persistEncoded(
            $run,
            RunArtifactKind::CONTEXT_PACKAGE,
            $prepared['bytes'],
            $prepared['metadata'],
            $context,
        );
    }

    /**
     * @param  list<string>  $criterionRefs
     * @param  array<string, mixed>  $slotBindings
     */
    public function store(Run $run, RunAgent $slot, int $round, array $criterionRefs, array $slotBindings, RedactionContext $context): RunArtifact
    {
        return $this->persist($run, $this->prepare($run, $slot, $round, $criterionRefs, $slotBindings, $context), $context);
    }

    /** @param array<string, mixed> $slotBindings */
    private function bindingHash(Run $run, array $slotBindings): string
    {
        return hash('sha256', 'AI6-CONTEXT-PACKAGE-BINDING-V1'."\0".$this->json->normalizeAndEncode([
            'run_id' => $run->id,
            'ticket_blob_sha' => $run->ticket_blob_sha,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash,
            'workspace_tree_hash' => $slotBindings['workspace_tree_hash'] ?? null,
            'scope_hash' => $run->effective_scope_hash ?? $run->scope_hash,
            'prompt_hash' => $slotBindings['slot_prompt_hash'] ?? null,
            'instruction_hash' => $slotBindings['slot_instruction_hash'] ?? null,
            'provider_runtime_profile_hash' => $slotBindings['slot_runtime_profile_hash'] ?? null,
            'security_policy_hash' => $run->security_policy_hash,
        ]));
    }
}
