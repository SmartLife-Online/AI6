<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\Models\CriterionCoverage;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\After;

/**
 * A running implementation run with bound workspace and finished preflight,
 * plus the persisted evidence the AI6-031 run page shows: artifacts under a
 * temporary trusted root and check results under the real SQLite guards.
 */
trait BuildsObservedRunFixture
{
    use BuildsHumanRequestFixture;

    private ?string $observedArtifactRoot = null;

    #[After]
    public function removeObservedArtifactRoot(): void
    {
        Date::setTestNow();
        if ($this->observedArtifactRoot !== null && is_dir($this->observedArtifactRoot)) {
            $this->removeObservedTree($this->observedArtifactRoot);
        }
        $this->observedArtifactRoot = null;
    }

    protected function observedArtifactRoot(): string
    {
        if ($this->observedArtifactRoot === null) {
            $this->observedArtifactRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-031-'.bin2hex(random_bytes(6));
            self::assertTrue(mkdir($this->observedArtifactRoot, 0700, true));
            config(['ai6.run_artifacts.root' => $this->observedArtifactRoot]);
            $this->app->forgetInstance(RunArtifactRoot::class);
            $this->app->forgetInstance(RunArtifactStore::class);
        }

        return $this->observedArtifactRoot;
    }

    /** @return array{Run, Project, User} */
    protected function observedRun(string $ticketId, ?Project $project = null): array
    {
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @param list<string> $ticketFiles */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $this->observedArtifactRoot();
        Mail::fake();
        $attention = $this->createUser(['email' => 'attention-'.strtolower($ticketId).'@example.test']);
        $fixture = $this->completedApproval($ticketId, $project, attentionUser: $attention);
        $run = $this->finalizedRun($fixture);
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $run));
        DB::table('jobs')->delete();

        return [$run->refresh(), $fixture['project']->refresh(), $fixture['operator']];
    }

    /**
     * A finalized run in a second provisioned project, for foreign-reference
     * cases: its own identifier, its own operator with the start entitlement.
     *
     * @return array{Run, Project, User}
     */
    protected function secondObservedRun(string $ticketId): array
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->secondObservedProject($administrator);
        $operator = $this->createUser();
        $this->addMembership($operator, $project, ProjectRole::OPERATOR);
        $fixture = $this->completedApproval($ticketId, $project, $operator);
        $run = $this->finalizedRun($fixture);
        DB::table('jobs')->delete();

        return [$run->refresh(), $project->refresh(), $operator];
    }

    /** A second provisioned project with its own identifier, for foreign-reference cases. */
    protected function secondObservedProject(User $administrator): Project
    {
        $project = Project::query()->create([
            'name' => 'Zweitprojekt '.bin2hex(random_bytes(4)),
            'remote' => 'git@git.example.test:acme/second-'.bin2hex(random_bytes(4)).'.git',
            'control_branch' => 'refs/heads/main',
            'project_identifier' => substr(hash('sha256', 'second-'.bin2hex(random_bytes(8))), 0, 32),
            'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(random_bytes(32)), '='),
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/test-key-second',
            'public_deploy_key' => "ssh-ed25519 fixture-second\n",
            'control_oid' => str_repeat('b', 64),
        ]);
        $this->addMembership($administrator, $project, ProjectRole::ADMIN);

        return $project->refresh();
    }

    /** @param array<string, mixed> $metadata */
    protected function storeObservedArtifact(Run $run, RunArtifactKind $kind, string $bytes, array $metadata = []): RunArtifact
    {
        $this->observedArtifactRoot();

        return $this->app->make(RunArtifactStore::class)->store(
            $run->fresh() ?? $run,
            $kind,
            $bytes,
            $metadata + ['kind' => $kind->value],
            new RedactionContext((string) $run->project_id, $run->id, 'run-observation-test'),
        );
    }

    /**
     * A check result exactly as the runner persists it: the output has already
     * crossed the central redaction at its persistence boundary.
     */
    protected function seedObservedCheckResult(Run $run, string $output, string $profile = 'php-targeted'): CheckResultRecord
    {
        $tree = (string) $run->checkpoint_tree_sha;
        $redacted = $this->app->make(Redactor::class)->redact(
            $output,
            new RedactionContext((string) $run->project_id, $run->id, 'check-output-test'),
        )->text;

        return CheckResultRecord::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'phase' => CheckPhase::BEFORE_REVIEW,
            'evidence_epoch' => $run->evidence_epoch, 'profile' => $profile,
            'state' => CheckResultState::FAILED, 'reason' => 'check_failed', 'exit_code' => 1,
            'duration_ms' => 12, 'redacted_output' => $redacted, 'tree_sha' => $tree, 'result_tree_sha' => $tree,
            'declared_side_effects' => false, 'declared_network' => false, 'declared_mutates' => false,
            'result_key' => CheckResult::key($run->id, $run->evidence_epoch, CheckPhase::BEFORE_REVIEW, $profile, $tree),
        ]);
    }

    /**
     * A valid quality-review result with one criterion-coverage row whose
     * status is stored exactly as the parser persists it: raw, unredacted.
     */
    protected function seedObservedCoverage(Run $run, string $status, string $evidence = 'Gebundener Testnachweis.'): CriterionCoverage
    {
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $run->fresh() ?? $run;
        if (! RunAgent::query()->where('run_id', $run->id)->where('role', 'quality_review')->exists()) {
            $orchestrator->materializeReviewSlots($run);
        }
        $slot = RunAgent::query()->where('run_id', $run->id)->where('role', 'quality_review')->where('is_active', true)->firstOrFail();
        if ($slot->session_id === null) {
            $slot = $orchestrator->bindReviewSession($run, $slot->slot_id, (string) Str::uuid());
        }
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"review-'.Str::uuid().'"}');
        $approval = TicketApproval::query()->findOrFail($run->ticket_approval_id);
        $result = ReviewResult::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'round_number' => 1,
            'slot_id' => $slot->slot_id, 'attempt' => 1, 'role' => 'quality_review',
            'provider_profile' => $slot->provider_profile, 'model' => $slot->model,
            'effort' => $slot->effort, 'prompt_profile' => $slot->prompt_profile,
            'session_id' => $slot->session_id, 'checkpoint_commit_sha' => $run->checkpoint_commit_sha,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha, 'diff_hash' => $run->checkpoint_diff_hash,
            'approval_config_hash' => $run->config_hash, 'approval_scope_hash' => $run->scope_hash,
            'approval_prompt_hash' => $run->prompt_hash, 'approval_instruction_hash' => $run->instruction_hash,
            'approval_runtime_profile_hash' => $run->runtime_profile_hash,
            'approval_agent_profile_hash' => $run->agent_profile_hash,
            'approval_security_policy_hash' => $run->security_policy_hash,
            'approval_snapshot_hash' => $approval->approval_snapshot_hash, 'workspace_tree_hash' => $run->checkpoint_tree_sha,
            'invocation_outcome' => ReviewInvocationOutcome::VALID_RESULT, 'result_status' => 'nothing_to_fix',
            'raw_artifact_id' => $artifact->id,
        ]);

        return CriterionCoverage::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'review_result_id' => $result->id,
            'round_number' => 1, 'slot_id' => $slot->slot_id, 'criterion_id' => 'AC-01',
            'status' => $status, 'evidence' => $evidence,
        ]);
    }

    protected function observedArtifactPath(RunArtifact $artifact): ?string
    {
        if ($artifact->storage_reference === null) {
            return null;
        }

        return $this->observedArtifactRoot().DIRECTORY_SEPARATOR.$artifact->run_id.DIRECTORY_SEPARATOR.basename($artifact->storage_reference);
    }

    private function removeObservedTree(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeObservedTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
