<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRecoveryRequired;
use App\AI6\Git\ControlOperationRetryableConflict;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\DeployKeyProvisioner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class ControlOperationPersistenceTest extends ControlOperationTestCase
{
    public function test_operation_and_database_job_are_persisted_atomically_and_same_id_is_idempotent(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operationId = (string) Str::uuid();
        $action = $this->app->make(QueueDeployKeyProvisioning::class);

        $operation = $action->handle($administrator, $project, $operationId);
        $same = $action->handle($administrator, $project->refresh(), $operationId);

        self::assertSame($operationId, $same->id);
        self::assertSame(1, ControlOperation::query()->count());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(ControlOperationType::DEPLOY_KEY_PROVISION, $operation->operation_type);
        self::assertSame(ControlOperationPhase::QUEUED, $operation->phase);
        self::assertSame(ControlOperationState::QUEUED, $operation->state);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $operation->request_hash);
        self::assertSame(ProjectProvisioningStatus::PROVISIONING, $project->refresh()->provisioning_status);
        self::assertSame($operationId, $project->provisioning_operation_id);
        self::assertSame($operationId, $project->operation_lock_operation_id);
        self::assertSame(1, $project->operation_lock_attempt_token);
        self::assertNotNull($project->operation_lock_lease_expires_at);
        self::assertNotNull($project->operation_lock_heartbeat_at);
        self::assertSame(1, $operation->current_attempt_token);
    }

    public function test_queue_insert_failure_rolls_back_operation_and_project_transition(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        DB::unprepared("CREATE TRIGGER reject_control_job BEFORE INSERT ON jobs BEGIN SELECT RAISE(ABORT, 'queue rejected'); END");

        try {
            $this->app->make(QueueDeployKeyProvisioning::class)->handle(
                $administrator,
                $project,
                (string) Str::uuid(),
            );
            self::fail('The operation unexpectedly committed without its database job.');
        } catch (QueryException) {
            self::assertSame(0, ControlOperation::query()->count());
            self::assertSame(0, DB::table('jobs')->count());
            self::assertSame(ProjectProvisioningStatus::NOT_PROVISIONED, $project->refresh()->provisioning_status);
            self::assertNull($project->provisioning_operation_id);
            self::assertNull($project->operation_lock_operation_id);
        }
    }

    public function test_a_failed_request_can_be_requeued_with_a_new_operation_id_and_the_same_request_hash(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $action = $this->app->make(QueueDeployKeyProvisioning::class);
        $first = $action->handle($administrator, $project, (string) Str::uuid());
        ControlOperationResult::query()->create([
            'control_operation_id' => $first->id,
            'outcome' => ControlOperationOutcome::FAILED,
            'result_binding' => hash('sha256', 'first-failed-request'),
            'safe_summary' => 'Erster Versuch fehlgeschlagen.',
        ]);
        $first->forceFill([
            'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
            'state' => ControlOperationState::FAILED,
            'completed_at' => now(),
        ])->save();
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONING_FAILED,
            'provisioning_operation_id' => null,
            'operation_lock_operation_id' => null,
            'operation_lock_lease_expires_at' => null,
            'operation_lock_heartbeat_at' => null,
        ])->save();

        $second = $action->handle($administrator, $project->refresh(), (string) Str::uuid());

        self::assertNotSame($first->id, $second->id);
        self::assertSame($first->request_hash, $second->request_hash);
        self::assertSame(2, ControlOperation::query()->count());
    }

    public function test_claim_and_publish_architecture_has_one_atomic_claim_seam_and_owner_bound_publish(): void
    {
        $writers = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            // Catches both the array-key write form used by Eloquent update()/insert()/
            // forceFill() calls (single- or double-quoted) and a raw-SQL assignment form
            // (e.g. inside DB::statement()/DB::unprepared() "SET operation_lock_operation_id = ..."),
            // so a second writer cannot slip in under a different spelling.
            $writesField = preg_match(
                '/[\'"]operation_lock_operation_id[\'"]\s*=>'
                .'|(?<![\'"\w])operation_lock_operation_id(?![\'"\w])\s*=(?!=|>)/',
                $source,
            ) === 1;
            if ($writesField) {
                $writers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
        self::assertSame(['app/AI6/Git/ProjectOperationLease.php'], $writers);

        $claim = $this->methodSource(ProjectOperationLease::class, 'claim');
        self::assertSame(
            1,
            substr_count($claim, '->whereKey($operation->project_id)'),
            'claim() must fence the project-lock compare-and-swap through exactly one atomic UPDATE seam, not a per-branch pair of independent updates.',
        );
        self::assertSame(
            1,
            substr_count($claim, "'operation_lock_attempt_token' =>"),
            'claim() must set the project-lock attempt token from a single SET clause shared by every claim branch.',
        );

        $publish = $this->methodSource(DeployKeyProvisioner::class, 'finalizeProvisioning');
        self::assertStringContainsString("->where('operation_lock_operation_id', \$operation->id)", $publish);
        self::assertStringContainsString("->where('operation_lock_attempt_token', \$attemptToken)", $publish);
        self::assertStringNotContainsString("whereNull('operation_lock_operation_id')", $publish);
        self::assertLessThan(strpos($publish, '->update(['), strpos($publish, "->where('operation_lock_attempt_token'"));
    }

    public function test_only_one_operation_claims_a_project_and_stale_tokens_cannot_progress_or_release(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $first = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        $second = ControlOperation::query()->create([
            'id' => (string) Str::uuid(),
            'project_id' => $project->getKey(),
            'actor_id' => $administrator->getKey(),
            'operation_type' => ControlOperationType::DEPLOY_KEY_PROVISION,
            'schema_version' => 1,
            'authorization_snapshot' => ['actor_id' => $administrator->getKey()],
            'authorization_snapshot_jcs' => '{"actor_id":'.$administrator->getKey().'}',
            'operation_parameters_jcs' => '{"algorithm":"ed25519"}',
            'request_hash' => hash('sha256', 'second'),
            'phase' => ControlOperationPhase::QUEUED,
            'state' => ControlOperationState::QUEUED,
        ]);
        $lease = $this->app->make(ProjectOperationLease::class);

        $firstToken = $lease->claim($first, str_repeat('b', 32));
        self::assertSame(1, $firstToken);
        self::assertNull($lease->claim($second, str_repeat('c', 32)));

        DB::table('projects')->where('id', $project->getKey())->update([
            'operation_lock_lease_expires_at' => now()->subSecond(),
        ]);
        $first->refresh();
        $secondToken = $lease->claim($first, str_repeat('d', 32));

        self::assertSame(2, $secondToken);
        self::assertFalse($lease->heartbeat($first->id, $project->getKey(), $firstToken));
        self::assertFalse($lease->release($first->id, $project->getKey(), $firstToken));
        self::assertTrue($lease->heartbeat($first->id, $project->getKey(), $secondToken));
        self::assertFalse($lease->release($first->id, $project->getKey(), $secondToken));
        ControlOperationResult::query()->create([
            'control_operation_id' => $first->id,
            'outcome' => ControlOperationOutcome::FAILED,
            'result_binding' => hash('sha256', 'lease-test-result'),
            'safe_summary' => 'Lease test terminal result.',
        ]);
        $first->refresh()->forceFill([
            'phase' => ControlOperationPhase::ATTEMPT_COMPLETED,
            'state' => ControlOperationState::FAILED,
            'completed_at' => now(),
        ])->save();
        self::assertTrue($lease->release($first->id, $project->getKey(), $secondToken));
    }

    public function test_non_administrator_cannot_submit_provisioning(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $viewer = $this->createUser();
        $project = $this->registeredProject($administrator);
        $this->addMembership($viewer, $project);

        $this->expectException(AuthorizationException::class);
        $this->app->make(QueueDeployKeyProvisioning::class)->handle($viewer, $project, (string) Str::uuid());
    }

    public function test_finalize_provisioning_rejects_a_foreign_attempt_token_over_the_real_worker_path(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $operation = $this->reachKeyActivated($administrator, $project->refresh());

        try {
            $this->app->make(DeployKeyProvisioner::class)->advance($operation, 999);
            self::fail('A foreign attempt token unexpectedly reached provisioning finalization.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ControlOperationRetryableConflict::class, $exception);
            self::assertSame('lease_lost', $exception->conflict);
            self::assertSame('The operation lease was lost before provisioning finalization.', $exception->getMessage());
        }

        self::assertSame(ControlOperationPhase::KEY_ACTIVATED, $operation->refresh()->phase);
        self::assertSame(ProjectProvisioningStatus::PROVISIONING, $project->refresh()->provisioning_status);
    }

    public function test_finalize_provisioning_requires_recovery_when_the_active_intent_binds_a_foreign_operation(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $operation = $this->reachKeyActivated($administrator, $project->refresh());

        $paths = $this->app->make(ManagedProjectPath::class);
        $active = $paths->activeDirectory((string) $project->refresh()->project_identifier);
        $fingerprint = hash_file('sha256', $active.'/id_ed25519.pub');
        self::assertIsString($fingerprint);
        self::assertNotFalse(file_put_contents($active.'/intent.json', json_encode([
            'schema' => 1,
            'operation_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', 'foreign-request'),
            'attempt_token' => 1,
            'public_key_fingerprint' => $fingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"));
        chmod($active.'/intent.json', 0600);

        try {
            $this->app->make(DeployKeyProvisioner::class)->advance($operation, 1);
            self::fail('A foreign operation-id binding was unexpectedly accepted at finalization.');
        } catch (Throwable $exception) {
            self::assertSame(ControlOperationRecoveryRequired::class, $exception::class);
            self::assertSame('The active deploy key lost its operation binding before finalization.', $exception->getMessage());
        }
    }

    #[DataProvider('changedPriorProjectStateProvider')]
    public function test_finalize_provisioning_requires_recovery_when_prior_project_state_changed(
        string $field,
        \Closure $mutate,
    ): void {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $operation = $this->reachKeyActivated($administrator, $project->refresh());

        $mutate($project->refresh());

        try {
            $this->app->make(DeployKeyProvisioner::class)->advance($operation, 1);
            self::fail(sprintf('A changed %s was unexpectedly accepted at finalization.', $field));
        } catch (Throwable $exception) {
            self::assertSame(ControlOperationRecoveryRequired::class, $exception::class);
            self::assertSame('Project provenance changed before deploy-key finalization.', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, \Closure(Project): void}> */
    public static function changedPriorProjectStateProvider(): iterable
    {
        yield 'provisioning_operation_id' => ['provisioning_operation_id', static function (Project $project): void {
            $project->forceFill(['provisioning_operation_id' => (string) Str::uuid()])->save();
        }];
        yield 'control_oid' => ['control_oid', static function (Project $project): void {
            $project->forceFill(['control_oid' => str_repeat('c', 40)])->save();
        }];
    }

    /**
     * Drives the real DeployKeyProvisioner up to KEY_ACTIVATED with a genuinely
     * bound active deploy key (real ssh-keygen material and a matching intent.json),
     * the same real state finalizeProvisioning() reads on the worker's production path.
     */
    private function reachKeyActivated(User $administrator, Project $project): ControlOperation
    {
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        $paths = $this->app->make(ManagedProjectPath::class);
        $active = $paths->activeDirectory((string) $project->refresh()->project_identifier);
        $this->generateTestKeyPair($active, 'ai6:'.$operation->id.':'.$operation->request_hash);
        $fingerprint = hash_file('sha256', $active.'/id_ed25519.pub');
        self::assertIsString($fingerprint);
        self::assertNotFalse(file_put_contents($active.'/intent.json', json_encode([
            'schema' => 1,
            'operation_id' => $operation->id,
            'request_hash' => $operation->request_hash,
            'attempt_token' => 1,
            'public_key_fingerprint' => $fingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n"));
        chmod($active.'/intent.json', 0600);

        $operation->forceFill([
            'phase' => ControlOperationPhase::KEY_ACTIVATED,
            'state' => ControlOperationState::RUNNING,
            'attempts' => 1,
            'current_attempt_token' => 1,
            'effect_attempt_token' => 1,
            'key_fingerprint' => $fingerprint,
            'version' => $operation->version + 1,
        ])->save();

        return $operation->refresh();
    }

    private function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $lines = file($reflection->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
