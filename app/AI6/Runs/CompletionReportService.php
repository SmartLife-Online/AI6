<?php

namespace App\AI6\Runs;

use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\Models\Intervention;
use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunGate;
use App\AI6\Shared\Redaction\RedactionContext;

/** Run-type-independent deterministic report projection. */
final readonly class CompletionReportService
{
    public function __construct(
        private RunArtifactStore $artifacts,
        private EffectiveFindingState $findingStates,
    ) {}

    /** @return array{bytes: string, metadata: array<string, string>} */
    public function prepare(Run $run): array
    {
        $context = new RedactionContext((string) $run->project_id, $run->id, 'completion-report');
        $findings = [];
        $findingIds = Finding::query()->where('run_id', $run->id)->orderBy('id')->pluck('id');
        foreach ($findingIds as $findingId) {
            $finding = Finding::query()->findOrFail($findingId);
            $effective = $this->findingStates->currentDisposition($finding, $run);
            $findings[] = [
                'id' => $finding->id,
                'slot_id' => $finding->slot_id,
                'severity' => $finding->severity->value,
                'original_disposition' => $finding->original_disposition->value,
                'effective_disposition' => $effective?->type->value ?? $finding->original_disposition->value,
                'blocks' => $this->findingStates->blocks($finding, $run, $effective),
                'category' => $finding->category->value,
                'file' => $finding->file,
                'line' => $finding->line,
                'title' => $finding->title,
                'evidence' => $finding->evidence,
                'expected_result' => $finding->expected_result,
                'criterion_refs' => $finding->criterion_refs,
                'duplicate_group' => $finding->duplicate_group,
            ];
        }
        $slots = [];
        foreach ((new ReviewResult)->newQuery()->where('run_id', $run->id)->orderBy('round_number')->orderBy('slot_id')->get() as $result) {
            $slots[] = [
                'round' => $result->round_number,
                'slot_id' => $result->slot_id,
                'provider_profile' => $result->provider_profile,
                'model' => $result->model,
                'effort' => $result->effort,
                'prompt_profile' => $result->prompt_profile,
                'outcome' => $result->invocation_outcome->value,
            ];
        }
        $checks = [];
        foreach ((new CheckResultRecord)->newQuery()->where('run_id', $run->id)->whereNull('superseded_at')->orderBy('phase')->orderBy('profile')->get() as $result) {
            $checks[] = ['profile' => $result->profile, 'phase' => $result->phase->value, 'state' => $result->state->value, 'tree_sha' => $result->tree_sha];
        }
        $coverage = [];
        foreach ((new CriterionCoverage)->newQuery()->where('run_id', $run->id)->orderBy('round_number')->orderBy('slot_id')->orderBy('criterion_id')->get() as $entry) {
            $coverage[] = ['round' => $entry->round_number, 'slot_id' => $entry->slot_id, 'criterion_id' => $entry->criterion_id, 'status' => $entry->status, 'evidence' => $entry->evidence];
        }
        $decisions = [];
        foreach ((new HumanRequest)->newQuery()->where('run_id', $run->id)->orderBy('id')->get() as $request) {
            $intervention = (new Intervention)->newQuery()->where('human_request_id', $request->id)->first();
            $decisions[] = ['id' => $request->id, 'kind' => $request->kind, 'resolution' => $request->resolution_state->value, 'effect' => $intervention instanceof Intervention ? $intervention->chosen_effect : null];
        }
        $gates = [];
        foreach ((new RunGate)->newQuery()->where('run_id', $run->id)->orderBy('gate_id')->get() as $gate) {
            $gates[] = ['id' => $gate->gate_id, 'state' => $gate->state->value, 'evidence_reference' => $gate->evidence_reference];
        }
        $artifacts = [];
        foreach ((new RunArtifact)->newQuery()->where('run_id', $run->id)->where('kind', '<>', RunArtifactKind::COMPLETION_REPORT->value)->orderBy('sequence')->get() as $artifact) {
            // The report binds every artifact by its central keyed fingerprint,
            // never by the unkeyed digest or the storage path: both disappear
            // with the retention deletion and must not survive here (SEC-011).
            $artifacts[] = [
                'id' => $artifact->id,
                'kind' => $artifact->kind->value,
                'sequence' => $artifact->sequence,
                'size_bytes' => $artifact->size_bytes,
                'fingerprint' => $artifact->fingerprint,
                'fingerprint_key_id' => $artifact->fingerprint_key_id,
                'fingerprint_version' => $artifact->fingerprint_version,
                'retention_state' => $artifact->retention_state->value,
            ];
        }

        $payload = [
            'schema' => 'ai6.completion-report.v1',
            'run' => [
                'id' => $run->id,
                'type' => $run->run_type->value,
                'ticket_blob_sha' => $run->ticket_blob_sha,
                'ticket_contract_sha256' => $run->ticket_contract_sha256,
                'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
                'diff_hash' => $run->checkpoint_diff_hash,
            ],
            'slots' => $slots,
            'checks' => $checks,
            'criterion_coverage' => $coverage,
            'findings' => $findings,
            'human_decisions' => $decisions,
            'gates' => $gates,
            'artifacts' => $artifacts,
        ];

        return [
            'bytes' => $this->artifacts->encodeCanonicalJson($payload, $context),
            'metadata' => [
                'schema' => 'ai6.completion-report.v1',
                'checkpoint_tree_sha' => (string) $run->checkpoint_tree_sha,
                'diff_hash' => (string) $run->checkpoint_diff_hash,
            ],
        ];
    }

    /** @param array{bytes: string, metadata: array<string, string>} $prepared */
    public function persist(Run $run, array $prepared): RunArtifact
    {
        return $this->artifacts->persistEncoded(
            $run,
            RunArtifactKind::COMPLETION_REPORT,
            $prepared['bytes'],
            $prepared['metadata'],
            new RedactionContext((string) $run->project_id, $run->id, 'completion-report'),
        );
    }

    public function build(Run $run): RunArtifact
    {
        return $this->persist($run, $this->prepare($run));
    }
}
