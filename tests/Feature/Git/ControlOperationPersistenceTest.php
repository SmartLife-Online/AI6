<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\DeployKeyProvisioner;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            if (preg_match("/'operation_lock_operation_id'\\s*=>/", $source) === 1) {
                $writers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
        self::assertSame(['app/AI6/Git/ProjectOperationLease.php'], $writers);

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
