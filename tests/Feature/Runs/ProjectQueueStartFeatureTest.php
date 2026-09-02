<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueRunStart;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Runs\ApprovalClaimStarter;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ApprovalSnapshotVerifier;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\EvaluateTicketApproval;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\QueueAutoStarter;
use App\AI6\Runs\QueueEligibility;
use App\AI6\Runs\QueueReevaluation;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ProjectQueueStartFeatureTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @return list<InstructionCandidate> */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_unsatisfied_dependency_stays_queued_then_is_claimed_on_fresh_eligibility(): void
    {
        $fixture = $this->completedApproval(
            'QUEUE-DEPENDENT-1',
            dependsOn: '[QUEUE-DEPENDENCY-1]',
            dependencyStatuses: ['QUEUE-DEPENDENCY-1' => 'todo'],
        );
        $starter = $this->app->make(ApprovalClaimStarter::class);
        try {
            $starter->start($fixture['operator'], $fixture['project'], $fixture['approval']->id, (string) Str::uuid());
            self::fail('An approval with an unsatisfied dependency was claimed.');
        } catch (ControlOperationConflict $exception) {
            self::assertStringContainsString('dependency_unsatisfied:QUEUE-DEPENDENCY-1', $exception->getMessage());
        }
        self::assertSame('queued', $fixture['approval']->fresh()->queue_state);
        self::assertSame(0, Run::query()->count());

        TicketReadModel::query()->where('project_id', $fixture['project']->id)
            ->where('relative_path', 'tickets/QUEUE-DEPENDENCY-1.md')->delete();
        $this->publishReadModel(
            $fixture['administrator'],
            $fixture['project'],
            'tickets/QUEUE-DEPENDENCY-1.md',
            $this->validTicketMarkdown('QUEUE-DEPENDENCY-1', 'done'),
        );
        $operation = $starter->start(
            $fixture['operator'],
            $fixture['project']->refresh(),
            $fixture['approval']->id,
            (string) Str::uuid(),
        );
        $run = $this->finalizedRun($fixture, operation: $operation);

        self::assertSame($fixture['approval']->id, $run->ticket_approval_id);
        self::assertSame('consumed', $fixture['approval']->fresh()->queue_state);
        self::assertSame(1, Run::query()->count());
    }

    public function test_revocation_and_ticket_contract_change_remain_visible_with_named_reasons(): void
    {
        $revoked = $this->completedApproval('QUEUE-REVOKED-1');
        self::assertSame(1, DB::table('ticket_approvals')->where('id', $revoked['approval']->id)->update([
            'queue_state' => 'cancelled',
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        $this->actingAs($revoked['operator'])
            ->get(route('projects.queue.index', $revoked['project']))
            ->assertOk()->assertSee('approval_cancelled');

        $changed = $this->completedApproval(
            'QUEUE-CHANGED-1',
            $revoked['project'],
            $revoked['operator'],
        );
        TicketReadModel::query()->where('project_id', $changed['project']->id)
            ->where('relative_path', $changed['approval']->relative_path)->delete();
        $this->publishReadModel(
            $changed['administrator'],
            $changed['project'],
            $changed['approval']->relative_path,
            $this->validTicketMarkdown('QUEUE-CHANGED-1', 'ready', '[]', 'Geänderter Ticketvertrag.'),
        );
        $evaluation = $this->app->make(QueueReevaluation::class)->scheduleApproval($changed['approval']);
        $this->app->call([new EvaluateTicketApproval($evaluation->id), 'handle']);
        $this->actingAs($changed['operator'])
            ->get(route('projects.queue.index', $changed['project']))
            ->assertOk()->assertSee('ticket_contract_changed');
        self::assertSame(0, Run::query()->count());
    }

    public function test_blocked_head_does_not_prevent_later_independent_run(): void
    {
        config()->set('ai6.project_config.server_defaults.auto_start_next', true);
        $this->forgetConfigurationBindings();
        $origin = $this->completedApproval('QUEUE-AUTO-ORIGIN-1');
        $completedRun = $this->finalizedRun($origin);
        self::assertSame(1, Run::query()->whereKey($completedRun->id)->update([
            'state' => RunState::COMPLETED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertSame(1, Project::query()->whereKey($origin['project']->id)->update(['active_run_id' => null]));

        $blocked = $this->completedApproval('QUEUE-BLOCKED-RUN-1', $origin['project']->refresh(), $origin['operator']);
        config()->set('ai6.project_config.server_defaults.dependency_satisfied_statuses', ['done', 'review']);
        $this->forgetConfigurationBindings();
        $next = $this->completedApproval('QUEUE-NEXT-RUN-1', $blocked['project'], $blocked['operator']);

        $operation = $this->app->make(QueueAutoStarter::class)->afterCompletion($blocked['project'], $completedRun->refresh());
        self::assertInstanceOf(ControlOperation::class, $operation);
        self::assertSame($origin['operator']->id, $operation->actor_id);
        $run = $this->finalizedRun($next, operation: $operation);

        self::assertSame($next['approval']->id, $run->ticket_approval_id);
        self::assertSame('queued', $blocked['approval']->fresh()->queue_state);
    }

    public function test_approval_actor_cannot_replace_start_run_authorization_for_automatic_claims(): void
    {
        $fixture = $this->completedApproval('QUEUE-AUTO-AUTHORIZATION-1');
        $approver = $fixture['approval']->approver()->firstOrFail();

        $this->expectException(AuthorizationException::class);
        $this->app->make(ApprovalClaimStarter::class)->start(
            $approver,
            $fixture['project'],
            $fixture['approval']->id,
            (string) Str::uuid(),
            automatic: true,
        );
    }

    public function test_disabled_auto_start_waits_for_the_manual_route_to_create_the_run(): void
    {
        $origin = $this->completedApproval('QUEUE-MANUAL-ORIGIN-1');
        $completedRun = $this->finalizedRun($origin);
        self::assertSame(1, Run::query()->whereKey($completedRun->id)->update([
            'state' => RunState::COMPLETED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertSame(1, Project::query()->whereKey($origin['project']->id)->update(['active_run_id' => null]));
        $fixture = $this->completedApproval('QUEUE-MANUAL-1', $origin['project']->refresh(), $origin['operator']);
        self::assertNull($this->app->make(QueueAutoStarter::class)->afterCompletion($fixture['project'], $completedRun->refresh()));
        self::assertSame(1, Run::query()->count());

        $this->actingAs($fixture['operator'])
            ->post(route('projects.approvals.start', [$fixture['project'], $fixture['approval']]))
            ->assertRedirect();
        $operation = ControlOperation::query()->findOrFail(
            $fixture['project']->refresh()->operation_lock_operation_id,
        );
        self::assertSame(ControlOperationType::RUN_START, $operation->operation_type);
        $run = $this->finalizedRun($fixture, operation: $operation);

        self::assertSame($fixture['approval']->id, $run->ticket_approval_id);
        self::assertSame(2, Run::query()->count());
    }

    public function test_claim_attempt_resolves_the_git_backed_snapshot_exactly_once(): void
    {
        $fixture = $this->completedApproval('QUEUE-SINGLE-ELIGIBILITY-1');
        $source = new class implements InstructionCandidateSource
        {
            public int $calls = 0;

            /** @return list<InstructionCandidate> */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance(InstructionCandidateSource::class, $source);
        foreach ([ApprovalSnapshotFactory::class, ApprovalSnapshotVerifier::class, QueueEligibility::class, ApprovalClaimStarter::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $this->app->make(ApprovalClaimStarter::class)->start(
            $fixture['operator'],
            $fixture['project'],
            $fixture['approval']->id,
            (string) Str::uuid(),
        );

        self::assertSame(1, $source->calls);
    }

    public function test_claim_reports_named_project_and_read_model_integrity_conflicts(): void
    {
        $missingIdentifier = $this->completedApproval('QUEUE-MISSING-PROJECT-ID-1');
        $projectWithoutIdentifier = $missingIdentifier['project']->refresh()->forceFill(['project_identifier' => null]);
        $decision = $this->app->make(QueueEligibility::class)->resolve(
            $missingIdentifier['approval'],
            $projectWithoutIdentifier,
        );
        self::assertContains('project_identifier_missing', $decision->reasons);
        self::assertNotNull($decision->readModel);
        try {
            $this->app->make(QueueRunStart::class)->handleVerified(
                $missingIdentifier['operator'],
                $projectWithoutIdentifier,
                $missingIdentifier['approval'],
                $decision->readModel,
                (string) Str::uuid(),
            );
            self::fail('A project without an identifier reached request hashing.');
        } catch (ControlOperationConflict $exception) {
            self::assertStringContainsString('project identifier is missing', $exception->getMessage());
        }

        $invalidDocument = $this->completedApproval(
            'QUEUE-INVALID-DOCUMENT-1',
            $missingIdentifier['project']->refresh(),
            $missingIdentifier['operator'],
        );
        TicketReadModel::query()->where('project_id', $invalidDocument['project']->id)
            ->where('relative_path', $invalidDocument['approval']->relative_path)
            ->delete();
        $this->publishReadModel(
            $invalidDocument['administrator'],
            $invalidDocument['project'],
            $invalidDocument['approval']->relative_path,
            "---\nstatus: todo\n---\n",
        );
        $this->assertClaimConflict($invalidDocument, 'ticket_document_invalid');

        $redactedDocument = $this->completedApproval(
            'QUEUE-REDACTED-DOCUMENT-1',
            $missingIdentifier['project']->refresh(),
            $missingIdentifier['operator'],
        );
        TicketReadModel::query()->where('project_id', $redactedDocument['project']->id)
            ->where('relative_path', $redactedDocument['approval']->relative_path)
            ->delete();
        $masked = $this->validTicketMarkdown(
            'QUEUE-REDACTED-DOCUMENT-1',
            'ready',
            '[]',
            'Ziel mit [REDACTED:SECRET].',
        );
        $this->publishReadModel(
            $redactedDocument['administrator'],
            $redactedDocument['project'],
            $redactedDocument['approval']->relative_path,
            $masked,
            [
                'redaction_state' => TicketReadModelRedactionState::CONTENT_REDACTED,
                'redaction_matches' => $this->redactionMatchFixture(),
                'source_blockers' => ['content_redacted'],
                'approval_eligible' => false,
                'editor_eligible' => false,
            ],
        );
        $this->assertClaimConflict($redactedDocument, 'ticket_redaction_not_clear');

        $inconsistentBlob = $this->completedApproval(
            'QUEUE-INCONSISTENT-BLOB-1',
            $missingIdentifier['project']->refresh(),
            $missingIdentifier['operator'],
        );
        TicketReadModel::query()->where('project_id', $inconsistentBlob['project']->id)
            ->where('relative_path', $inconsistentBlob['approval']->relative_path)
            ->delete();
        $this->publishReadModel(
            $inconsistentBlob['administrator'],
            $inconsistentBlob['project'],
            $inconsistentBlob['approval']->relative_path,
            $this->validTicketMarkdown('QUEUE-INCONSISTENT-BLOB-1', 'ready'),
            ['blob_sha' => str_repeat('f', 64)],
        );
        $this->assertClaimConflict($inconsistentBlob, 'ticket_blob_inconsistent');
    }

    public function test_auto_start_waits_for_active_run_pending_control_sync_and_project_operation_lock(): void
    {
        config()->set('ai6.project_config.server_defaults.auto_start_next', true);
        $this->forgetConfigurationBindings();
        $completed = $this->completedApproval('QUEUE-GATE-COMPLETED-1');
        $completedRun = $this->finalizedRun($completed);
        self::assertSame(1, Run::query()->whereKey($completedRun->id)->update([
            'state' => RunState::COMPLETED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertSame(1, Project::query()->whereKey($completedRun->project_id)->update(['active_run_id' => null]));
        $active = $this->completedApproval(
            'QUEUE-GATE-ACTIVE-1',
            $completed['project']->refresh(),
            $completed['operator'],
        );
        $activeRun = $this->finalizedRun($active);
        $waiting = $this->completedApproval(
            'QUEUE-GATE-WAITING-1',
            $completed['project']->refresh(),
            $completed['operator'],
        );
        $starter = $this->app->make(QueueAutoStarter::class);

        self::assertNull($starter->afterCompletion($waiting['project']->refresh(), $completedRun->refresh()));
        self::assertSame(2, Run::query()->count());

        self::assertSame(1, Run::query()->whereKey($activeRun->id)->update([
            'state' => RunState::CANCELLED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertSame(1, Project::query()->whereKey($activeRun->project_id)
            ->where('active_run_id', $activeRun->id)->update(['active_run_id' => null]));

        self::assertSame(1, Project::query()->whereKey($activeRun->project_id)->update([
            'pending_control_ref' => 'refs/heads/replacement',
            'pending_control_oid' => str_repeat('a', 64),
            'pending_control_operation_id' => (string) Str::uuid(),
        ]));
        self::assertNull($starter->afterCompletion($waiting['project']->refresh(), $completedRun->refresh()));
        self::assertSame(2, Run::query()->count());
        self::assertSame(1, Project::query()->whereKey($activeRun->project_id)->update([
            'pending_control_ref' => null,
            'pending_control_oid' => null,
            'pending_control_operation_id' => null,
        ]));

        $reserved = $this->queueStart($waiting);

        self::assertNotNull($waiting['project']->refresh()->operation_lock_operation_id);
        self::assertNull($starter->afterCompletion($waiting['project']->refresh(), $completedRun->refresh()));
        self::assertSame(1, ControlOperation::query()
            ->where('operation_type', ControlOperationType::RUN_START->value)
            ->whereKey($reserved->id)->count());

        $run = $this->finalizedRun($waiting, operation: $reserved);
        self::assertSame($waiting['approval']->id, $run->ticket_approval_id);
        self::assertSame(3, Run::query()->count());
    }

    public function test_two_parallel_auto_start_attempts_claim_exactly_one_next_run(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('TC-12 requires Linux, pcntl and a shared SQLite database.');
        }
        $this->useForkSafeDatabase();
        config()->set('ai6.project_config.server_defaults.auto_start_next', true);
        $this->forgetConfigurationBindings();

        $origin = $this->completedApproval('QUEUE-PARALLEL-ORIGIN-1');
        $completedRun = $this->finalizedRun($origin);
        self::assertSame(1, Run::query()->whereKey($completedRun->id)->update([
            'state' => RunState::COMPLETED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertSame(1, Project::query()->whereKey($origin['project']->id)->update(['active_run_id' => null]));
        $waiting = $this->completedApproval(
            'QUEUE-PARALLEL-NEXT-1',
            $origin['project']->refresh(),
            $origin['operator'],
        );

        $barrier = sys_get_temp_dir().'/ai6-queue-parallel-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($barrier, 0700));
        DB::disconnect('sqlite');
        DB::purge('sqlite');
        $children = [];
        foreach ([1, 2] as $index) {
            $pid = pcntl_fork();
            self::assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                while (! is_file($barrier.'/go')) {
                    usleep(1000);
                }
                DB::purge('sqlite');
                try {
                    $operation = $this->app->make(QueueAutoStarter::class)->afterCompletion(
                        Project::query()->findOrFail($waiting['project']->id),
                        Run::query()->findOrFail($completedRun->id),
                    );
                    $outcome = $operation instanceof ControlOperation ? $operation->id : 'none';
                    file_put_contents($barrier.'/result-'.$index, $outcome);
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($barrier.'/result-'.$index, 'error:'.class_basename($exception));
                    exit(1);
                }
            }
            $children[] = $pid;
        }

        try {
            self::assertNotFalse(file_put_contents($barrier.'/go', 'go'));
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            $outcomes = [
                (string) file_get_contents($barrier.'/result-1'),
                (string) file_get_contents($barrier.'/result-2'),
            ];
            self::assertSame(1, count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'none')));
            $operationId = array_values(array_filter($outcomes, static fn (string $outcome): bool => $outcome !== 'none'))[0];
            $operation = ControlOperation::query()->findOrFail($operationId);
            $run = $this->finalizedRun($waiting, operation: $operation);

            self::assertSame($waiting['approval']->id, $run->ticket_approval_id);
            self::assertSame(1, Run::query()->where('ticket_approval_id', $waiting['approval']->id)->count());
            self::assertNull($waiting['project']->refresh()->operation_lock_operation_id);
        } finally {
            foreach (['go', 'result-1', 'result-2'] as $name) {
                if (is_file($barrier.'/'.$name)) {
                    unlink($barrier.'/'.$name);
                }
            }
            if (is_dir($barrier)) {
                rmdir($barrier);
            }
        }
    }

    private function forgetConfigurationBindings(): void
    {
        foreach ([EffectiveProjectConfiguration::class, ApprovalSnapshotFactory::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    /** @param array{operator: User, project: Project, approval: TicketApproval} $fixture */
    private function assertClaimConflict(array $fixture, string $reason): void
    {
        try {
            $this->app->make(ApprovalClaimStarter::class)->start(
                $fixture['operator'],
                $fixture['project']->refresh(),
                $fixture['approval']->id,
                (string) Str::uuid(),
            );
            self::fail('The invalid queue entry was claimed: '.$reason);
        } catch (ControlOperationConflict $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }
}
