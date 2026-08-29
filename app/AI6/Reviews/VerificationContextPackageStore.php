<?php

namespace App\AI6\Reviews;

use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\CanonicalJson;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Support\Facades\DB;

final readonly class VerificationContextPackageStore
{
    public function __construct(
        private RunArtifactStore $artifacts,
        private CanonicalJson $json,
        private Redactor $redactor,
    ) {}

    /** @param array<string, mixed> $bindings */
    public function store(
        Run $run,
        RunAgent $slot,
        Finding $finding,
        int $round,
        array $bindings,
        string $workspace,
        RedactionContext $context,
    ): void {
        $redact = fn (string $value): string => $this->redactor->redact($value, $context)->text;
        $checkIds = DB::table('check_results')->where('run_id', $run->id)->whereNull('superseded_at')
            ->where('tree_sha', $run->checkpoint_tree_sha)->orderBy('profile')->pluck('id');
        $checks = [];
        foreach ($checkIds as $checkId) {
            if (! is_string($checkId)) {
                continue;
            }
            $check = CheckResultRecord::query()->find($checkId);
            if (! $check instanceof CheckResultRecord) {
                continue;
            }
            $checks[] = [
                'profile' => $check->profile,
                'state' => $check->state->value,
                'output' => $check->redacted_output,
            ];
        }
        $relevantCode = [];
        $root = realpath($workspace);
        $candidate = realpath($workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $finding->file));
        if (is_string($root) && is_string($candidate)
            && str_starts_with($candidate, rtrim($root, '/\\').DIRECTORY_SEPARATOR)
            && is_file($candidate)) {
            $contents = file_get_contents($candidate);
            if (is_string($contents)) {
                $relevantCode[] = ['path' => $redact($finding->file), 'content' => $redact($contents)];
            }
        }
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $payload = [
            'schema' => 'ai6.verification-context-package.v1',
            'stage' => 'finding_verification:'.$round.':'.$slot->slot_id,
            'run_id' => $run->id,
            'ticket_blob_sha' => $run->ticket_blob_sha ?? $approval->approved_ticket_blob_sha ?? $approval->reviewed_ticket_blob_sha,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash,
            'scope' => $run->effective_scope_snapshot ?? (($run->scope_snapshot ?? [])['ticket_files'] ?? []),
            'scope_hash' => $run->effective_scope_hash ?? $run->scope_hash,
            'prompt_hash' => $bindings['slot_prompt_hash'] ?? null,
            'instruction_hash' => $bindings['slot_instruction_hash'] ?? null,
            'provider_runtime_profile_hash' => $bindings['slot_runtime_profile_hash'] ?? null,
            'security_policy_hash' => $run->security_policy_hash,
            'finding' => [
                'id' => $finding->id,
                'duplicate_group' => $finding->duplicate_group,
                'source_provider_profile' => $finding->provider_profile,
                'source_model' => $finding->model,
                'title' => $redact($finding->title),
                'file' => $redact($finding->file),
                'line' => $finding->line,
                'evidence' => $redact($finding->evidence),
                'expected_result' => $redact($finding->expected_result),
                'criterion_refs' => $finding->criterion_refs,
            ],
            'relevant_code' => $relevantCode,
            'check_results' => $checks,
        ];
        $bytes = $this->artifacts->encodeCanonicalJson($payload, $context);
        $bindingHash = hash('sha256', 'AI6-VERIFICATION-CONTEXT-V1'."\0".$this->json->normalizeAndEncode($payload));
        $existing = RunArtifact::query()->where('run_id', $run->id)
            ->where('kind', RunArtifactKind::CONTEXT_PACKAGE->value)->get()
            ->first(static fn (RunArtifact $artifact): bool => ($artifact->redacted_metadata['stage'] ?? null) === $payload['stage']);
        if ($existing instanceof RunArtifact) {
            if (! hash_equals((string) ($existing->redacted_metadata['binding_hash'] ?? ''), $bindingHash)) {
                throw new ReviewResultParseException('verification_context_package_binding_changed');
            }

            return;
        }
        $this->artifacts->store($run, RunArtifactKind::CONTEXT_PACKAGE, $bytes, [
            'stage' => $payload['stage'],
            'binding_hash' => $bindingHash,
        ], $context);
    }
}
