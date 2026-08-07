<?php

namespace Tests\Feature\Git;

use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use Illuminate\Support\Str;

final class RecordRecoveryDecisionTest extends ControlOperationTestCase
{
    public function test_a_failed_validation_does_not_consume_the_freshness_proof(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
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
        $operation->refresh();

        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => 'control_operation_recovery']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        // Abandonment without a reason fails the domain validation inside RecordRecoveryDecision::handle()
        // that runs after the step-up guard. Under the old ordering, consumeFresh() had already burned the
        // freshness proof before this validation ran, so the retry below would have needed a second step-up.
        $this->post(route('projects.operations.recover', [$project, $operation]), [
            'decision' => 'abandon_operation',
            'expected_attempt_token' => $operation->recovery_attempt_token,
            'expected_operation_version' => $operation->recovery_version,
            'finding_hash' => $operation->finding_hash,
        ])->assertSessionHasErrors();

        self::assertSame(0, ControlOperationRecoveryDecision::query()->count());

        // The freshness proof must still be valid: a corrected, valid request in the same session succeeds
        // without repeating step-up.
        $this->post(route('projects.operations.recover', [$project, $operation]), [
            'decision' => 'retry_reconciliation',
            'expected_attempt_token' => $operation->recovery_attempt_token,
            'expected_operation_version' => $operation->recovery_version,
            'finding_hash' => $operation->finding_hash,
        ])->assertRedirect(route('projects.operations.show', [$project, $operation]));

        $record = ControlOperationRecoveryDecision::query()->sole();
        self::assertSame('retry_reconciliation', $record->decision->value);
    }
}
