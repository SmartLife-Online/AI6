<?php

namespace Tests\Feature\Tickets;

use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationRecoveryRequired;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\ManagedCloneSynchronizer;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\TicketMutationExecutor;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Tickets\TicketMutationConflict;
use App\AI6\Tickets\TicketStatusOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class TicketMutationQueueTest extends TicketUiTestCase
{
    public function test_ready_edit_queues_one_bound_operation_and_couples_status_to_todo(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T9', 'ready', '[]', 'Alter Inhalt.');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T9.md', $base);
        $operation = $this->app->make(QueueTicketMutation::class)->edit(
            $actor,
            $project->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $base,
            str_replace('Alter Inhalt.', 'Neuer Inhalt.', $base),
            'api_key=audit-value-123',
            true,
        );

        self::assertSame(ControlOperationType::TICKET_EDIT, $operation->operation_type);
        self::assertSame(ControlOperationPhase::PREPARED, $operation->phase);
        self::assertSame($operation->id, $project->refresh()->operation_lock_operation_id);
        self::assertSame(1, DB::table('jobs')->count());
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        self::assertSame('ready', $mutation->source_status);
        self::assertSame('todo', $mutation->target_status);
        self::assertStringContainsString("status: todo\n", $mutation->target_content);
        self::assertStringContainsString('Neuer Inhalt.', $mutation->target_content);
        self::assertStringNotContainsString('audit-value-123', $mutation->audit_reason);
        self::assertStringContainsString('[REDACTED:SECRET]', $mutation->audit_reason);
    }

    public function test_stale_or_masked_editor_base_creates_no_mutation(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T9', 'todo', '[]', 'Gebundener Inhalt.');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T9.md', $base);

        try {
            $this->app->make(QueueTicketMutation::class)->edit(
                $actor,
                $project,
                $readModel,
                (string) Str::uuid(),
                $readModel->control_commit,
                $readModel->blob_sha,
                $base.'manipuliert',
                $base,
                'Korrektur',
                true,
            );
            self::fail('A manipulated editor base was accepted.');
        } catch (TicketMutationConflict $exception) {
            self::assertSame('editor_base_binding_changed', $exception->conflict);
        }
        self::assertSame(0, ControlOperation::query()->whereIn('operation_type', [
            ControlOperationType::TICKET_EDIT,
            ControlOperationType::TICKET_STATUS_CHANGE,
        ])->count());

        $maskedContent = $this->validTicketMarkdown('R9', 'todo', '[]', RedactionMatchType::SECRET->marker());
        $masked = $this->publishReadModel($actor, $project, 'tickets/R9.md', $maskedContent, [
            'redaction_state' => TicketReadModelRedactionState::CONTENT_REDACTED,
            'redaction_matches' => $this->redactionMatchFixture(),
            'source_blockers' => ['content_redacted'],
            'editor_eligible' => false,
            'approval_eligible' => false,
        ]);
        try {
            $this->app->make(QueueTicketMutation::class)->edit(
                $actor,
                $project,
                $masked,
                (string) Str::uuid(),
                $masked->control_commit,
                $masked->blob_sha,
                $masked->redacted_content,
                $masked->redacted_content,
                'Korrektur',
                true,
            );
            self::fail('A masked projection was accepted.');
        } catch (TicketMutationConflict $exception) {
            self::assertSame('editor_unavailable', $exception->conflict);
        }
        self::assertSame(0, TicketMutation::query()->count());
    }

    public function test_fixed_status_operation_is_bound_without_accepting_a_free_target(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T10', 'todo');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T10.md', $base);

        $operation = $this->app->make(QueueTicketMutation::class)->changeStatus(
            $actor,
            $project->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $base,
            'Fachliche Blockade',
            true,
            TicketStatusOperation::BLOCK,
            false,
        );

        self::assertSame(ControlOperationType::TICKET_STATUS_CHANGE, $operation->operation_type);
        self::assertSame('block', json_decode($operation->operation_parameters_jcs, true, flags: JSON_THROW_ON_ERROR)['status_operation']);
        $mutation = TicketMutation::query()->findOrFail($operation->id);
        self::assertSame('todo', $mutation->source_status);
        self::assertSame('blocked', $mutation->target_status);
        self::assertStringContainsString("status: blocked\n", $mutation->target_content);
    }

    public function test_malformed_editor_target_is_a_named_conflict_and_writes_nothing(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T11', 'todo');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T11.md', $base);

        try {
            $this->app->make(QueueTicketMutation::class)->edit(
                $actor,
                $project->refresh(),
                $readModel,
                (string) Str::uuid(),
                $readModel->control_commit,
                $readModel->blob_sha,
                $base,
                'kein Ticket',
                'Korrektur',
                true,
            );
            self::fail('A malformed target was accepted.');
        } catch (TicketMutationConflict $exception) {
            self::assertSame('target_validation_failed', $exception->conflict);
        }

        self::assertSame(0, TicketMutation::query()->count());
    }

    public function test_clear_invalid_projection_can_be_queued_only_as_a_valid_repair(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $target = $this->validTicketMarkdown('T12', 'todo');
        $base = str_replace("status: todo\n", '', $target);
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T12.md', $base);

        $operation = $this->app->make(QueueTicketMutation::class)->edit(
            $actor,
            $project->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $base,
            $target,
            'Status repariert',
            true,
        );

        $mutation = TicketMutation::query()->findOrFail($operation->id);
        self::assertSame('todo', $mutation->source_status);
        self::assertSame('todo', $mutation->target_status);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $mutation->target_contract_sha256);
    }

    public function test_readable_non_human_source_status_never_uses_invalid_document_fallback(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        foreach (['in_progress', 'done', 'cancelled'] as $status) {
            $base = $this->validTicketMarkdown('T-'.$status, $status);
            $readModel = $this->publishReadModel($actor, $project, 'tickets/T-'.$status.'.md', $base);

            try {
                $this->app->make(QueueTicketMutation::class)->edit(
                    $actor,
                    $project->refresh(),
                    $readModel,
                    (string) Str::uuid(),
                    $readModel->control_commit,
                    $readModel->blob_sha,
                    $base,
                    str_replace('status: '.$status, 'status: todo', $base),
                    'Unzulässiger Rücksprung',
                    true,
                );
                self::fail('A readable non-human source status used the repair fallback.');
            } catch (TicketMutationConflict $exception) {
                self::assertSame('source_status_unavailable', $exception->conflict);
            }
        }

        self::assertSame(0, TicketMutation::query()->count());
    }

    public function test_project_claim_is_atomic_and_has_one_documented_extension_seam(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T13', 'todo');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T13.md', $base);
        $queue = $this->app->make(QueueTicketMutation::class);

        $queue->edit(
            $actor,
            $project->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $base,
            str_replace('Ziel des Tickets.', 'Erster Entwurf.', $base),
            'Erster Edit',
            true,
        );

        try {
            $queue->edit(
                $actor,
                $project->refresh(),
                $readModel,
                (string) Str::uuid(),
                $readModel->control_commit,
                $readModel->blob_sha,
                $base,
                str_replace('Ziel des Tickets.', 'Zweiter Entwurf.', $base),
                'Zweiter Edit',
                true,
            );
            self::fail('A second mutation obtained the project claim.');
        } catch (TicketMutationConflict $exception) {
            self::assertSame('project_operation_locked', $exception->conflict);
        }

        self::assertSame(1, TicketMutation::query()->count());
        self::assertSame(1, DB::table('jobs')->count());

        $queueSource = file_get_contents(app_path('AI6/Git/Actions/QueueTicketMutation.php'));
        $leaseSource = file_get_contents(app_path('AI6/Git/ProjectOperationLease.php'));
        self::assertIsString($queueSource);
        self::assertIsString($leaseSource);
        self::assertSame(1, substr_count($queueSource, 'claimInitialControlOperation('));
        self::assertStringContainsString('AI6-013 extends this exact compare-and-swap seam with the active-run guard.', $leaseSource);
    }

    public function test_redaction_marker_in_clear_editor_target_is_rejected_before_queueing(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T14', 'todo');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T14.md', $base);
        $parts = explode('Ziel des Tickets.', $base, 2);
        $maskedTarget = $parts[0].RedactionMatchType::SECRET->marker().$parts[1];

        try {
            $this->app->make(QueueTicketMutation::class)->edit(
                $actor,
                $project->refresh(),
                $readModel,
                (string) Str::uuid(),
                $readModel->control_commit,
                $readModel->blob_sha,
                $base,
                $maskedTarget,
                'Maskierte Projektion',
                true,
            );
            self::fail('A redaction marker was accepted as write content.');
        } catch (TicketMutationConflict $exception) {
            self::assertSame('redaction_marker_detected', $exception->conflict);
        }

        self::assertSame(0, TicketMutation::query()->count());
    }

    public function test_terminal_preflight_deviation_after_control_confirmation_requires_recovery(): void
    {
        $actor = $this->createUser();
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T15', 'todo');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T15.md', $base);
        $operation = $this->app->make(QueueTicketMutation::class)->edit(
            $actor,
            $project->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $base,
            str_replace('Ziel des Tickets.', 'Recovery-Ziel.', $base),
            'Recovery-Vertrag',
            true,
        );
        $token = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('a', 32));
        self::assertIsInt($token);
        $commit = str_repeat('b', 64);
        TicketMutation::query()->whereKey($operation->id)->update([
            'expected_target_tree_oid' => str_repeat('c', 64),
            'prepared_commit_oid' => $commit,
            'prepared_attempt_token' => $token,
        ]);
        ControlOperation::query()->whereKey($operation->id)->update([
            'target_control_oid' => $commit,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED,
            'state' => ControlOperationState::RUNNING,
        ]);
        DB::table('project_memberships')->where('project_id', $project->getKey())->where('user_id', $actor->getKey())->delete();

        $this->expectException(ControlOperationRecoveryRequired::class);
        $this->app->make(TicketMutationExecutor::class)->advance($operation->refresh(), $token);
    }

    public function test_operation_id_replay_and_wrong_parent_are_named_conflicts(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T16', 'todo');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T16.md', $base);
        $queue = $this->app->make(QueueTicketMutation::class);
        $operationId = (string) Str::uuid();
        $target = str_replace('Ziel des Tickets.', 'Gebundenes Ziel.', $base);
        $first = $queue->edit(
            $actor, $project->refresh(), $readModel, $operationId,
            $readModel->control_commit, $readModel->blob_sha, $base, $target, 'Replay-Bindung', true,
        );
        $same = $queue->edit(
            $actor, $project->refresh(), $readModel, $operationId,
            $readModel->control_commit, $readModel->blob_sha, $base, $target, 'Replay-Bindung', true,
        );
        self::assertSame($first->id, $same->id);
        self::assertSame(1, DB::table('jobs')->count());

        try {
            $queue->edit(
                $actor, $project->refresh(), $readModel, $operationId,
                $readModel->control_commit, $readModel->blob_sha, $base,
                str_replace('Ziel des Tickets.', 'Fremdes Ziel.', $base), 'Replay-Bindung', true,
            );
            self::fail('An operation identifier was rebound to different content.');
        } catch (ControlOperationConflict $exception) {
            self::assertStringContainsString('already bound', $exception->getMessage());
        }

        try {
            $queue->edit(
                $actor, $project->refresh(), $readModel, (string) Str::uuid(),
                str_repeat('f', 64), $readModel->blob_sha, $base, $target, 'Falscher Parent', true,
            );
            self::fail('A wrong expected parent was accepted.');
        } catch (TicketMutationConflict $exception) {
            self::assertSame('editor_base_binding_changed', $exception->conflict);
        }
    }

    public function test_managed_clone_recovery_methods_reject_ticket_mutations_before_any_effect(): void
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('T17', 'todo');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/T17.md', $base);
        $operation = $this->app->make(QueueTicketMutation::class)->changeStatus(
            $actor,
            $project->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $base,
            'Handler-Typgrenze',
            true,
            TicketStatusOperation::BLOCK,
            false,
        );
        $handler = $this->app->make(ManagedCloneSynchronizer::class);
        $decision = new ControlOperationRecoveryDecision;

        foreach ([
            'retryRecovery' => static fn () => $handler->retryRecovery($operation, $decision),
            'abandon' => static fn () => $handler->abandon($operation, $decision),
        ] as $method => $invoke) {
            try {
                $invoke();
                self::fail('Managed-clone recovery accepted a ticket mutation through '.$method.'.');
            } catch (RuntimeException $exception) {
                self::assertSame('The operation is not a managed-clone operation.', $exception->getMessage());
            }
        }
    }

    public function test_ticket_mutation_abandonment_never_rewrites_published_control_history(): void
    {
        $method = new \ReflectionMethod(TicketMutationExecutor::class, 'abandon');
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertStringNotContainsString('pushCommitCas(', $source);
        self::assertStringNotContainsString('updateRef(', $source);
        self::assertStringContainsString('cannot be abandoned without rewriting control history', $source);
    }
}
