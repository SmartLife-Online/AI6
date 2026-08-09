<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ControlOperationRecoveryTest extends ControlOperationTestCase
{
    public function test_recovery_requires_global_administrator_and_fresh_single_use_step_up(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        $payload = [
            ...$this->decisionPayload($operation),
            'reason' => 'password=hunter2',
        ];

        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $this->post(route('projects.operations.recover', [$project, $operation]), $payload)
            ->assertForbidden()
            ->assertSee('frische Step-up-Bestätigung');

        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])
            ->assertRedirect();
        $decisionRequestedAt = now();
        $this->post(route('projects.operations.recover', [$project, $operation]), $payload)
            ->assertRedirect(route('projects.operations.show', [$project, $operation]));

        $record = ControlOperationRecoveryDecision::query()->sole();
        self::assertSame($administrator->getKey(), $record->actor_id);
        self::assertNotNull($record->created_at);
        self::assertTrue($record->created_at->betweenIncluded(
            $decisionRequestedAt->copy()->startOfSecond(),
            now()->addSecond(),
        ));
        self::assertSame('retry_reconciliation', $record->decision->value);
        self::assertSame('pending', $record->state);
        self::assertSame($operation->finding_hash, $record->finding_hash);
        self::assertSame('password=[REDACTED:SECRET]', $record->reason);
        $this->get(route('projects.operations.show', [$project, $operation]))
            ->assertOk()
            ->assertSee($administrator->name)
            ->assertSee($record->created_at->toIso8601String())
            ->assertSee('retry_reconciliation')
            ->assertSee('[REDACTED:SECRET]');

        $this->post(route('projects.operations.recover', [$project, $operation]), $payload)
            ->assertForbidden();
    }

    public function test_abandonment_by_non_global_admin_is_denied(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        $administrator->forceFill(['is_global_admin' => false])->save();

        $this->actingAs($administrator)
            ->post(route('projects.operations.recover', [$project, $operation]), [
                ...$this->decisionPayload($operation),
                'decision' => 'abandon_operation',
            ])
            ->assertForbidden();

        self::assertSame(0, ControlOperationRecoveryDecision::query()->count());
    }

    public function test_abandonment_without_a_reason_is_a_named_conflict_with_no_decision_recorded(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $this->post(route('projects.operations.recover', [$project, $operation]), [
            ...$this->decisionPayload($operation),
            'decision' => 'abandon_operation',
            'evidence_commit' => str_repeat('a', 40),
        ])->assertSessionHasErrors('decision');

        self::assertSame(0, ControlOperationRecoveryDecision::query()->count());
    }

    public function test_abandonment_without_bound_evidence_is_a_named_conflict_with_no_decision_recorded(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $this->post(route('projects.operations.recover', [$project, $operation]), [
            ...$this->decisionPayload($operation),
            'decision' => 'abandon_operation',
            'reason' => 'Der Außenstand wurde geprüft.',
        ])->assertSessionHasErrors('decision');

        self::assertSame(0, ControlOperationRecoveryDecision::query()->count());
    }

    public function test_abandonment_evidence_is_typed_and_server_bound_to_project_operation_finding_and_commit(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $this->post(route('projects.operations.recover', [$project, $operation]), [
            ...$this->decisionPayload($operation),
            'decision' => 'abandon_operation',
            'reason' => 'Der Außenstand wurde geprüft.',
            'evidence_commit' => str_repeat('a', 40),
        ])->assertRedirect(route('projects.operations.show', [$project, $operation]));

        $record = ControlOperationRecoveryDecision::query()->sole();
        $evidence = json_decode((string) $record->bound_evidence, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('ai6.control-recovery-evidence.v1', $evidence['schema']);
        self::assertSame($project->getKey(), $evidence['project_id']);
        self::assertSame($operation->id, $evidence['operation_id']);
        self::assertSame($operation->finding_hash, $evidence['finding_hash']);
        self::assertSame(str_repeat('a', 40), $evidence['commit']);
        self::assertNull($evidence['base_commit']);
        self::assertNull($evidence['diff_sha256']);
    }

    public function test_pending_recovery_decision_is_claimed_under_a_new_attempt_without_reconciler_release(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        DB::table('jobs')->delete();
        $project->forceFill([
            'operation_lock_operation_id' => $operation->id,
            'operation_lock_attempt_token' => 1,
            'operation_lock_lease_expires_at' => now()->subSecond(),
            'operation_lock_heartbeat_at' => now()->subMinute(),
        ])->save();
        ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $operation->id,
            'actor_id' => $administrator->getKey(),
            'decision' => RecoveryDecisionType::RETRY_RECONCILIATION,
            'expected_attempt_token' => 1,
            'expected_operation_version' => 7,
            'finding_hash' => $operation->finding_hash,
            'state' => 'pending',
        ]);

        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame($operation->id, $project->refresh()->operation_lock_operation_id);

        $token = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('b', 32));

        self::assertSame(2, $token);
        $operation->refresh();
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->state);
        self::assertSame(1, $operation->recovery_attempt_token);
        self::assertSame(7, $operation->recovery_version);
        self::assertSame(2, $operation->current_attempt_token);
        self::assertFalse($this->app->make(ProjectOperationLease::class)->release($operation->id, $project->getKey(), 2));
        self::assertSame($operation->id, $project->refresh()->operation_lock_operation_id);
    }

    public function test_recovery_state_is_visible_and_rejects_a_second_mutating_operation_as_a_named_conflict(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);

        try {
            $this->app->make(QueueDeployKeyProvisioning::class)->handle(
                $administrator,
                $project->refresh(),
                (string) Str::uuid(),
            );
            self::fail('A second mutating operation was accepted while recovery held the project lease.');
        } catch (ControlOperationConflict $exception) {
            self::assertStringContainsString('already active', $exception->getMessage());
        }

        self::assertSame(1, ControlOperation::query()->count());
        self::assertSame($operation->id, $project->refresh()->operation_lock_operation_id);
        $this->actingAs($administrator)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('recovery_required')
            ->assertSee(route('projects.operations.show', [$project, $operation]));
    }

    public function test_invalid_recovery_decision_value_is_rejected_without_effect(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        $jobsBefore = DB::table('jobs')->count();
        $versionBefore = $operation->version;

        $this->actingAs($administrator)
            ->post(route('projects.operations.recover', [$project, $operation]), [
                ...$this->decisionPayload($operation),
                'decision' => 'delete_external_state',
            ])
            ->assertSessionHasErrors('decision');

        self::assertSame(0, ControlOperationRecoveryDecision::query()->count());
        self::assertSame($versionBefore, $operation->refresh()->version);
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->state);
        self::assertSame($operation->id, $project->refresh()->operation_lock_operation_id);
        self::assertSame($jobsBefore, DB::table('jobs')->count());
    }

    public function test_recovery_decision_rejects_a_tuple_after_current_attempt_and_version_advanced(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        ControlOperationRecoveryDecision::query()->create([
            'id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff',
            'control_operation_id' => $operation->id,
            'actor_id' => $administrator->getKey(),
            'decision' => RecoveryDecisionType::RETRY_RECONCILIATION,
            'expected_attempt_token' => 1,
            'expected_operation_version' => 7,
            'finding_hash' => $operation->finding_hash,
            'state' => 'rejected',
            'applied_at' => now(),
        ]);
        $operation->forceFill([
            'current_attempt_token' => 2,
            'version' => 9,
        ])->save();

        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $this->post(route('projects.operations.recover', [$project, $operation]), $this->decisionPayload($operation->refresh()))
            ->assertSessionHasErrors('decision');

        self::assertFalse($operation->recoveryDecision()->exists());
    }

    public function test_pending_relation_does_not_prefer_a_lexically_larger_finished_uuid(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        foreach ([
            ['ffffffff-ffff-4fff-bfff-ffffffffffff', 'rejected'],
            ['00000000-0000-4000-8000-000000000001', 'pending'],
        ] as [$id, $state]) {
            ControlOperationRecoveryDecision::query()->create([
                'id' => $id,
                'control_operation_id' => $operation->id,
                'actor_id' => $administrator->getKey(),
                'decision' => RecoveryDecisionType::RETRY_RECONCILIATION,
                'expected_attempt_token' => 1,
                'expected_operation_version' => 7,
                'finding_hash' => $operation->finding_hash,
                'state' => $state,
                'applied_at' => $state === 'pending' ? null : now(),
            ]);
        }

        self::assertSame('00000000-0000-4000-8000-000000000001', $operation->recoveryDecision()->sole()->id);
    }

    public function test_stale_recovery_finding_without_a_decision_is_requeued_without_releasing_project_lock(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        DB::table('jobs')->delete();
        $operation->forceFill(['updated_at' => now()->subMinute()])->save();
        $project->forceFill([
            'operation_lock_operation_id' => $operation->id,
            'operation_lock_attempt_token' => 1,
            'operation_lock_lease_expires_at' => now()->subSecond(),
            'operation_lock_heartbeat_at' => now()->subMinute(),
        ])->save();

        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame($operation->id, $project->refresh()->operation_lock_operation_id);
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $operation->refresh()->state);
    }

    public function test_recovery_decision_rejects_a_tuple_where_only_the_finding_hash_is_stale(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);

        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $this->post(route('projects.operations.recover', [$project, $operation]), [
            ...$this->decisionPayload($operation),
            'finding_hash' => hash('sha256', 'a-different-finding'),
        ])->assertSessionHasErrors('decision');

        self::assertFalse($operation->recoveryDecision()->exists());
    }

    public function test_recovery_decision_against_an_operation_that_already_left_recovery_state_is_a_conflict(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        $payload = $this->decisionPayload($operation);
        $operation->forceFill([
            'phase' => ControlOperationPhase::CLAIMED,
            'state' => ControlOperationState::RUNNING,
            'recovery_attempt_token' => null,
            'recovery_version' => null,
            'recovery_effect_hash' => null,
        ])->save();

        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $this->post(route('projects.operations.recover', [$project, $operation]), $payload)
            ->assertSessionHasErrors('decision');

        self::assertFalse($operation->recoveryDecision()->exists());
    }

    public function test_duplicate_recovery_decision_submission_is_rejected_as_a_pending_conflict(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $operation = $this->recoveryOperation($administrator, $project);
        ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $operation->id,
            'actor_id' => $administrator->getKey(),
            'decision' => RecoveryDecisionType::RETRY_RECONCILIATION,
            'expected_attempt_token' => $operation->recovery_attempt_token,
            'expected_operation_version' => $operation->recovery_version,
            'finding_hash' => $operation->finding_hash,
            'state' => 'pending',
        ]);

        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $this->post(route('projects.operations.recover', [$project, $operation]), $this->decisionPayload($operation))
            ->assertSessionHasErrors('decision');

        self::assertSame(1, ControlOperationRecoveryDecision::query()->count());
        self::assertSame('pending', ControlOperationRecoveryDecision::query()->sole()->state);
    }

    private function recoveryOperation(User $administrator, Project $project): ControlOperation
    {
        $operation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project,
            (string) Str::uuid(),
        );
        $operation->forceFill([
            'phase' => ControlOperationPhase::RECOVERY_REQUIRED,
            'state' => ControlOperationState::RECOVERY_REQUIRED,
            'attempts' => 1,
            'current_attempt_token' => 1,
            'version' => 7,
            'finding_text' => 'Ein aktiver Schlüssel ist nicht gebunden.',
            'finding_hash' => hash('sha256', 'finding'),
            'recovery_attempt_token' => 1,
            'recovery_version' => 7,
            'recovery_effect_hash' => hash('sha256', 'effect'),
        ])->save();

        return $operation->refresh();
    }

    /** @return array<string, int|string> */
    private function decisionPayload(ControlOperation $operation): array
    {
        return [
            'decision' => 'retry_reconciliation',
            'expected_attempt_token' => $operation->recovery_attempt_token,
            'expected_operation_version' => $operation->recovery_version,
            'finding_hash' => $operation->finding_hash,
        ];
    }
}
