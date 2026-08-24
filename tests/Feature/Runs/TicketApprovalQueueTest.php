<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\InstructionCandidate;
use App\AI6\Agents\InstructionCandidateOrigin;
use App\AI6\Agents\InstructionFileType;
use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\TicketMutationExecutor;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ApprovalStatusPage;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\BuildTicketApprovalPreview;
use App\AI6\Runs\Jobs\EvaluateTicketApproval;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Runs\Models\TicketApprovalPreview;
use App\AI6\Runs\TicketApprovalController;
use App\AI6\Runs\TicketApprovalPage;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Tickets\TicketMutationConflict;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Tickets\TicketUiTestCase;

final class TicketApprovalQueueTest extends TicketUiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_approval_persists_reviewed_binding_and_all_snapshots_before_queueing_effect(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('A12');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/A12.md', $content);
        $profiles = $this->app->make(AgentProfileRegistry::class);
        $selection = new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(),
                'profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'high',
                'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            $approver->getKey(),
            'manual',
        );

        $operationId = (string) Str::uuid();
        $preview = $this->app->make(ApprovalSnapshotFactory::class)->create($project, $readModel, $selection, $operationId);
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $project->refresh(),
            $readModel,
            $operationId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $content,
            'Menschlich geprüft',
            true,
            $selection,
            $preview,
            $operationId,
        );

        self::assertSame(ControlOperationType::TICKET_APPROVAL, $operation->operation_type);
        $approval = TicketApproval::query()->findOrFail($operation->id);
        self::assertSame($readModel->blob_sha, $approval->reviewed_ticket_blob_sha);
        self::assertSame($readModel->ticket_contract_sha256, $approval->ticket_contract_sha256);
        self::assertNull($approval->approved_ticket_blob_sha);
        self::assertSame('prepared', $approval->saga_phase);
        self::assertSame('pending_approval_effect', $approval->queue_state);
        self::assertCount(17, $approval->limits_snapshot);
        self::assertSame('2', $approval->prompt_snapshot['catalog_version']);
        self::assertSame('1', $approval->prompt_snapshot['fix_prompt_binding']['entry_version']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $approval->prompt_snapshot['fix_prompt_binding']['template_sha256']);
        self::assertArrayHasKey('fix', $approval->prompt_snapshot['rendered_prompts']);
        self::assertArrayHasKey('fake-v1', $approval->runtime_profile_snapshot);
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(0, DB::table('runs')->count());
        self::assertNull($project->fresh()->active_run_id);
        $this->actingAs($approver)
            ->get(route('projects.approvals.show', [$project, $approval]))
            ->assertOk()
            ->assertSee('pending_approval_effect')
            ->assertSee('evaluation_required');
        Livewire::actingAs($approver);
        $status = Livewire::test(ApprovalStatusPage::class, ['project' => $project, 'approvalId' => $approval->id])
            ->call('requestEvaluation');
        $evaluationId = $status->get('evaluationId');
        self::assertIsString($evaluationId);
        $jobsBeforeDuplicate = DB::table('jobs')->count();
        $status->call('requestEvaluation');
        self::assertSame($evaluationId, $status->get('evaluationId'));
        self::assertSame($jobsBeforeDuplicate, DB::table('jobs')->count());
        self::assertSame(1, TicketApprovalEvaluation::query()->where('state', 'queued')->count());
        $this->app->call([new EvaluateTicketApproval($evaluationId), 'handle']);
        $status->call('$refresh')->assertSee('approval_not_finalized');
        try {
            DB::table('ticket_approvals')->where('id', $approval->id)->update([
                'config_hash' => str_repeat('f', 64),
                'version' => 2,
            ]);
            self::fail('An immutable approval snapshot binding was changed.');
        } catch (QueryException) {
            self::assertSame($approval->config_hash, $approval->refresh()->config_hash);
        }
        $changedSelection = new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(),
                'profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'medium',
                'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            $approver->getKey(),
            'manual',
        );
        $this->expectException(ControlOperationConflict::class);
        $changedPreview = $this->app->make(ApprovalSnapshotFactory::class)->create($project, $readModel, $changedSelection, $operation->id);
        $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $project->refresh(),
            $readModel,
            $operation->id,
            $readModel->control_commit,
            $readModel->blob_sha,
            $content,
            'Menschlich geprüft',
            true,
            $changedSelection,
            $changedPreview,
            $operation->id,
        );
    }

    public function test_pending_approval_effect_is_cancelled_idempotently_after_a_terminal_conflict(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('A12C');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/A12C.md', $content);
        $selection = $this->selection();
        $operationId = (string) Str::uuid();
        $preview = $this->app->make(ApprovalSnapshotFactory::class)->create(
            $project,
            $readModel,
            $selection,
            $operationId,
        );
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $project->refresh(),
            $readModel,
            $operationId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $content,
            'Konfliktbereinigung prüfen',
            true,
            $selection,
            $preview,
            $operationId,
        );
        $approval = TicketApproval::query()->findOrFail($operation->id);
        $initialVersion = $approval->version;
        $executor = $this->app->make(TicketMutationExecutor::class);

        $executor->cancelApprovalEffect($operation);
        $approval->refresh();
        self::assertSame('conflict', $approval->saga_phase);
        self::assertSame('cancelled', $approval->queue_state);
        self::assertNull($approval->approved_ticket_blob_sha);
        self::assertNull($approval->approved_control_sha);
        self::assertSame($initialVersion + 1, $approval->version);

        $executor->cancelApprovalEffect($operation);
        self::assertSame($initialVersion + 1, $approval->refresh()->version);
    }

    public function test_retry_exhaustion_cancels_the_pending_approval_effect(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('A12F');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/A12F.md', $content);
        $selection = $this->selection();
        $operationId = (string) Str::uuid();
        $preview = $this->app->make(ApprovalSnapshotFactory::class)->create($project, $readModel, $selection, $operationId);
        $operation = $this->app->make(QueueTicketMutation::class)->approve(
            $approver,
            $project->refresh(),
            $readModel,
            $operationId,
            $readModel->control_commit,
            $readModel->blob_sha,
            $content,
            'Retry-Erschöpfung prüfen',
            true,
            $selection,
            $preview,
            $operationId,
        );
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('f', 32));
        self::assertIsInt($attemptToken);
        $maxAttempts = $this->app->make(ControlOperationConfiguration::class)->maxAttempts;
        self::assertSame(1, DB::table('control_operations')->where('id', $operation->id)->update([
            'attempts' => $maxAttempts,
        ]));
        self::assertSame($maxAttempts, $operation->refresh()->attempts);
        self::assertNull($operation->ticketMutation()->firstOrFail()->prepared_commit_oid);
        $managedRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-retry-exhaustion-'.$operationId;
        self::assertTrue(mkdir($managedRoot, 0700));
        $configuration = $this->app->make(ControlOperationConfiguration::class);
        $this->app->instance(ControlOperationConfiguration::class, new ControlOperationConfiguration(
            $managedRoot,
            $configuration->keyRoot,
            $configuration->sshKeygenBinary,
            $configuration->sshKeygenWrapper,
            $configuration->leaseSeconds,
            $configuration->heartbeatSeconds,
            $configuration->reconcilerSeconds,
            $configuration->maxAttempts,
            $configuration->knownHostsFile,
            $configuration->managedRefAllowlist,
            $configuration->staleSeconds,
            $configuration->reconciliationBudget,
        ));
        $this->app->forgetInstance(ManagedProjectPath::class);
        $this->app->forgetInstance(TicketMutationExecutor::class);
        $this->app->forgetInstance(ControlOperationExecutor::class);
        $recordFailure = new ReflectionMethod(ControlOperationExecutor::class, 'recordFailure');

        try {
            $recordFailure->invoke(
                $this->app->make(ControlOperationExecutor::class),
                $operation->refresh(),
                $attemptToken,
                new RuntimeException('Synthetic retry exhaustion.'),
            );
        } finally {
            self::assertTrue(rmdir($managedRoot));
        }

        $operation->refresh();
        self::assertSame(ControlOperationState::FAILED, $operation->state, json_encode([
            'phase' => $operation->phase->value,
            'last_error' => $operation->last_error,
            'finding' => $operation->finding_text,
        ], JSON_THROW_ON_ERROR));
        $approval = TicketApproval::query()->findOrFail($operation->id);
        self::assertSame('conflict', $approval->saga_phase);
        self::assertSame('cancelled', $approval->queue_state);
        self::assertNull($approval->approved_ticket_blob_sha);
        self::assertNull($approval->approved_control_sha);
        self::assertNull($project->refresh()->operation_lock_operation_id);
    }

    public function test_only_approvers_can_open_the_fresh_approval_page(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('A13');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/A13.md', $content);

        $this->actingAs($administrator)->get(route('projects.tickets.approval', [$project, $readModel]))->assertForbidden();
        $this->actingAs($approver)->get(route('projects.tickets.approval', [$project, $readModel]))->assertOk()->assertSee('Gemeinsam bestätigter Snapshot');
        Livewire::actingAs($approver);
        $component = Livewire::test(TicketApprovalPage::class, ['project' => $project, 'readModel' => (string) $readModel->getKey()]);
        $firstId = $component->get('reviewerInputs')[0]['id'];
        $component->call('addReviewer');
        $addedId = $component->get('reviewerInputs')[1]['id'];
        self::assertNotSame($firstId, $addedId);
        $component->call('removeReviewer', $addedId)->call('addReviewer');
        $replacementId = $component->get('reviewerInputs')[1]['id'];
        self::assertNotSame($addedId, $replacementId);
        $component->call('removeReviewer', $replacementId)
            ->set('implementationProfile', 'fake')
            ->set('reviewerInputs.0.profile', 'fake')
            ->call('requestPreview');
        $previewId = $component->get('previewId');
        self::assertIsString($previewId);
        $this->app->call([new BuildTicketApprovalPreview($previewId), 'handle']);
        $component->call('$refresh')->assertSee('expected_approval_snapshot_hash');
        $component->call('addReviewer');
        self::assertNull($component->get('previewId'));
    }

    public function test_ready_ticket_cannot_reenter_the_approval_page(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('A14', 'ready');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/A14.md', $content);

        $this->actingAs($approver)
            ->get(route('projects.tickets.approval', [$project, $readModel]))
            ->assertConflict();
    }

    public function test_prompt_hash_binds_every_selected_reviewer_prompt_profile(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/A15.md', $this->validTicketMarkdown('A15'));
        $profiles = $this->app->make(AgentProfileRegistry::class);
        $reviewers = $this->app->make(ReviewerSlotFactory::class);
        $limits = ApprovalLimits::fromConfiguredValues(
            config('ai6.project_config.server_defaults.limits'),
            $this->app->make(AgentInputLimits::class),
        );
        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();
        $selection = static fn (string $secondPrompt): ApprovalSelection => new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $reviewers->fromArray([
                ['id' => $firstId, 'profile' => 'fake', 'model' => 'fake-model', 'effort' => 'medium', 'prompt_profile' => 'security'],
                ['id' => $secondId, 'profile' => 'fake', 'model' => 'fake-model', 'effort' => 'high', 'prompt_profile' => $secondPrompt],
            ]),
            $limits,
            null,
            'manual',
        );
        $contextId = (string) Str::uuid();
        $factory = $this->app->make(ApprovalSnapshotFactory::class);
        $first = $factory->create($project, $readModel, $selection('tests'), $contextId);
        $same = $factory->create($project, $readModel, $selection('tests'), $contextId);
        $changed = $factory->create($project, $readModel, $selection('architecture'), $contextId);

        self::assertSame($first->promptHash, $same->promptHash);
        self::assertNotSame($first->promptHash, $changed->promptHash);
        self::assertNotSame($first->aggregateHash, $changed->aggregateHash);
        self::assertNotSame($first->prompt['prompt_snapshot_hash'], $first->promptHash);
    }

    public function test_http_approval_is_policy_step_up_preview_and_replay_bound(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('A16');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/A16.md', $content);

        Livewire::actingAs($approver);
        $component = Livewire::test(TicketApprovalPage::class, [
            'project' => $project,
            'readModel' => (string) $readModel->getKey(),
        ])->set('implementationProfile', 'fake')
            ->set('reviewerInputs.0.profile', 'fake')
            ->call('requestPreview');
        $previewId = $component->get('previewId');
        self::assertIsString($previewId);
        $this->app->call([new BuildTicketApprovalPreview($previewId), 'handle']);
        DB::table('jobs')->delete();
        $preview = TicketApprovalPreview::query()->findOrFail($previewId);
        $payload = [
            'operation_id' => $component->get('operationId'),
            'preview_id' => $previewId,
            'expected_control_oid' => $readModel->control_commit,
            'expected_blob' => $readModel->blob_sha,
            'base_content' => $content,
            'reason' => 'Menschliche Freigabe',
            'implementation_profile' => $component->get('implementationProfile'),
            'implementation_model' => $component->get('implementationModel'),
            'implementation_effort' => $component->get('implementationEffort'),
            'reviewers' => $component->get('reviewerInputs'),
            'limits' => $component->get('limitInputs'),
            'attention_user_id' => null,
            'push_mode' => $component->get('pushMode'),
            'confirm_snapshot' => '1',
            'expected_approval_snapshot_hash' => $preview->approval_snapshot_hash,
        ];

        $this->actingAs($administrator)
            ->post(route('projects.tickets.approval.store', [$project, $readModel]), $payload)
            ->assertForbidden();

        $this->actingAs($approver)
            ->post(route('projects.tickets.approval.store', [$project, $readModel]), $payload)
            ->assertForbidden();
        self::assertSame(0, TicketApproval::query()->count());

        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($approver);
        $this->post(route('auth.step-up.totp.verify', ['action' => TicketApprovalController::STEP_UP_ACTION]), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $tampered = $payload;
        $tampered['expected_approval_snapshot_hash'] = str_repeat('f', 64);
        $this->post(route('projects.tickets.approval.store', [$project, $readModel]), $tampered)
            ->assertSessionHasErrors('approval');
        self::assertSame(0, TicketApproval::query()->count());

        $changedBlob = $payload;
        $changedBlob['expected_blob'] = str_repeat('e', 64);
        $this->post(route('projects.tickets.approval.store', [$project, $readModel]), $changedBlob)
            ->assertSessionHasErrors('approval');
        self::assertSame(0, TicketApproval::query()->count());

        $changedControl = $payload;
        $changedControl['expected_control_oid'] = str_repeat('e', 64);
        $this->post(route('projects.tickets.approval.store', [$project, $readModel]), $changedControl)
            ->assertSessionHasErrors('approval');
        self::assertSame(0, TicketApproval::query()->count());

        $this->post(route('projects.tickets.approval.store', [$project, $readModel]), $payload)
            ->assertRedirect()
            ->assertSessionMissing('ai6.auth.step_up');
        self::assertSame(1, TicketApproval::query()->count());

        $this->post(route('projects.tickets.approval.store', [$project, $readModel]), $payload)
            ->assertForbidden();
        self::assertSame(1, TicketApproval::query()->count());
        self::assertSame(1, DB::table('jobs')->count());
    }

    public function test_http_profile_and_prompt_rejections_are_named_validation_errors(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('A17');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/A17.md', $content);
        $base = $this->minimalApprovalPayload($readModel, $content);

        $unknownProfile = $base;
        $unknownProfile['implementation_profile'] = 'raw-value-must-not-leak';
        $this->actingAs($approver)
            ->post(route('projects.tickets.approval.store', [$project, $readModel]), $unknownProfile)
            ->assertSessionHasErrors('approval');
        $profileErrors = session('errors');
        self::assertInstanceOf(ViewErrorBag::class, $profileErrors);
        $profileMessage = $profileErrors->getBag('default')->first('approval');
        self::assertSame('Die Agentenprofilauswahl wurde abgelehnt: profile_unknown.', $profileMessage);
        self::assertStringNotContainsString('raw-value-must-not-leak', $profileMessage);

        $unknownPrompt = $base;
        $unknownPrompt['reviewers'][0]['prompt_profile'] = 'raw-prompt-must-not-leak';
        $this->actingAs($approver)
            ->post(route('projects.tickets.approval.store', [$project, $readModel]), $unknownPrompt)
            ->assertSessionHasErrors('approval');
        $promptErrors = session('errors');
        self::assertInstanceOf(ViewErrorBag::class, $promptErrors);
        $promptMessage = $promptErrors->getBag('default')->first('approval');
        self::assertSame('Das Review-Promptprofil wurde abgelehnt: review_profile_unknown.', $promptMessage);
        self::assertStringNotContainsString('raw-prompt-must-not-leak', $promptMessage);
        self::assertSame(0, TicketApproval::query()->count());
    }

    public function test_invalid_masked_and_stale_sources_are_rejected_before_any_approval_effect(): void
    {
        foreach (['unparsed', 'invalid', 'content_redacted', 'stale'] as $case) {
            $administrator = $this->createUser(['is_global_admin' => true]);
            $approver = $this->createUser();
            $project = $this->provisionedProject($administrator);
            $this->addMembership($approver, $project, ProjectRole::APPROVER);
            $ids = match ($case) {
                'unparsed' => ['P1', 'U1'],
                'invalid' => ['P2', 'I1'],
                'content_redacted' => ['P3', 'R1'],
                default => ['P4', 'S1'],
            };
            $snapshotContent = $this->validTicketMarkdown($ids[0]);
            $snapshotSource = $this->publishReadModel($administrator, $project, 'tickets/'.$ids[0].'.md', $snapshotContent);
            $selection = $this->selection();
            $snapshot = $this->app->make(ApprovalSnapshotFactory::class)->create(
                $project,
                $snapshotSource,
                $selection,
                (string) Str::uuid(),
            );
            $content = $this->validTicketMarkdown($ids[1]);
            $maskedContent = $this->validTicketMarkdown($ids[1], 'todo', '[]', 'Ziel mit [REDACTED:SECRET].');
            $readModel = match ($case) {
                'unparsed' => $this->publishUnparsedReadModel($administrator, $project, 'tickets/U1.md', 'kein Ticket'),
                'invalid' => $this->publishReadModel($administrator, $project, 'tickets/I1.md', "---\nstatus: todo\n---\n"),
                'content_redacted' => $this->publishReadModel($administrator, $project, 'tickets/R1.md', $content, [
                    'redacted_content' => $maskedContent,
                    'redaction_state' => TicketReadModelRedactionState::CONTENT_REDACTED,
                    'redaction_matches' => $this->redactionMatchFixture(),
                    'source_blockers' => ['content_redacted'],
                    'approval_eligible' => false,
                    'editor_eligible' => false,
                ]),
                default => $this->publishReadModel($administrator, $project, 'tickets/S1.md', $content),
            };
            if ($case === 'stale') {
                $this->markProjectMovedOn($project);
            }

            try {
                $this->app->make(QueueTicketMutation::class)->approve(
                    $approver,
                    $project->refresh(),
                    $readModel,
                    (string) Str::uuid(),
                    $readModel->control_commit,
                    $readModel->blob_sha,
                    $readModel->redacted_content,
                    'Negativtest',
                    true,
                    $selection,
                    $snapshot,
                    (string) Str::uuid(),
                );
                self::fail('Die unzulässige Approval-Quelle wurde akzeptiert: '.$case);
            } catch (TicketMutationConflict $exception) {
                self::assertSame('editor_unavailable', $exception->conflict);
            }

            if ($case === 'content_redacted') {
                $this->actingAs($approver)
                    ->get(route('projects.tickets.show', [$project, $readModel]))
                    ->assertOk()
                    ->assertSee('[REDACTED:SECRET]')
                    ->assertDontSee('entfernter Klartext');
            }
            $project->delete();
        }
        self::assertSame(0, TicketApproval::query()->count());
    }

    #[DataProvider('approvalBoundaryCases')]
    public function test_preview_worker_preserves_typed_boundary_codes(string $case, string $expectedErrorCode): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $readModel = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/A18.md',
            $this->validTicketMarkdown('A18'),
        );
        if ($case === 'prompt_input') {
            config(['ai6.agent_input_limits.max_prompt_input_bytes' => 64]);
            $this->app->forgetInstance(AgentInputLimits::class);
        }
        $candidates = $this->boundaryCandidates($case);
        $this->app->instance(InstructionCandidateSource::class, new class($candidates) implements InstructionCandidateSource
        {
            /** @param list<InstructionCandidate> $candidates */
            public function __construct(private readonly array $candidates) {}

            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return $this->candidates;
            }
        });
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);
        Livewire::actingAs($approver);
        $component = Livewire::test(TicketApprovalPage::class, [
            'project' => $project,
            'readModel' => (string) $readModel->getKey(),
        ])->set('implementationProfile', 'fake')
            ->set('reviewerInputs.0.profile', 'fake')
            ->call('requestPreview');
        $previewId = $component->get('previewId');
        self::assertIsString($previewId);
        $this->app->call([new BuildTicketApprovalPreview($previewId), 'handle']);
        $preview = TicketApprovalPreview::query()->findOrFail($previewId);
        self::assertSame('conflict', $preview->state);
        self::assertSame($expectedErrorCode, $preview->error_code);
        self::assertNull($preview->approval_snapshot);
        self::assertNull($preview->approval_snapshot_hash);
    }

    /** @return array<string, array{string, string}> */
    public static function approvalBoundaryCases(): array
    {
        return [
            'instruction file count' => ['file_count', 'instruction_file_count_exceeded'],
            'instruction file bytes' => ['file_bytes', 'instruction_file_bytes_exceeded'],
            'instruction import depth' => ['import_depth', 'instruction_import_depth_exceeded'],
            'final prompt input' => ['prompt_input', 'prompt_input_bytes_exceeded'],
            'instruction import cycle' => ['import_cycle', 'instruction_import_cycle'],
            'canonical instruction path duplicate' => ['path_duplicate', 'instruction_path_duplicate'],
        ];
    }

    /** @return list<InstructionCandidate> */
    private function boundaryCandidates(string $case): array
    {
        if ($case === 'prompt_input') {
            return [];
        }
        if ($case === 'file_bytes') {
            return [$this->instructionCandidate(1, 'instructions/large.md', str_repeat('x', 262145))];
        }
        if ($case === 'import_cycle') {
            return [
                $this->instructionCandidate(1, 'instructions/first.md', 'Erste Instruktion', ['instructions/second.md']),
                $this->instructionCandidate(2, 'instructions/second.md', 'Zweite Instruktion', ['instructions/first.md']),
            ];
        }
        if ($case === 'path_duplicate') {
            return [
                $this->instructionCandidate(1, "instructions/caf\u{00E9}.md", 'Erste Instruktion'),
                $this->instructionCandidate(2, "instructions/cafe\u{0301}.md", 'Zweite Instruktion'),
            ];
        }

        $count = $case === 'file_count' ? 17 : 9;
        $candidates = [];
        for ($index = 0; $index < $count; $index++) {
            $path = 'instructions/'.$index.'.md';
            $imports = $case === 'import_depth' && $index < $count - 1
                ? ['instructions/'.($index + 1).'.md']
                : [];
            $candidates[] = $this->instructionCandidate($index + 1, $path, 'Instruktion '.$index, $imports);
        }

        return $candidates;
    }

    /** @param list<string> $imports */
    private function instructionCandidate(int $index, string $path, string $content, array $imports = []): InstructionCandidate
    {
        return new InstructionCandidate(
            'agents_md',
            InstructionCandidateOrigin::REPOSITORY,
            true,
            InstructionFileType::REGULAR,
            $path,
            str_pad(dechex($index), 40, '0', STR_PAD_LEFT),
            $content,
            $imports,
        );
    }

    /** @return array<string, mixed> */
    private function minimalApprovalPayload(TicketReadModel $readModel, string $content): array
    {
        return [
            'operation_id' => (string) Str::uuid(),
            'preview_id' => (string) Str::uuid(),
            'expected_control_oid' => $readModel->control_commit,
            'expected_blob' => $readModel->blob_sha,
            'base_content' => $content,
            'reason' => 'Menschliche Freigabe',
            'implementation_profile' => 'fake',
            'implementation_model' => 'fake-model',
            'implementation_effort' => 'medium',
            'reviewers' => [[
                'id' => (string) Str::uuid(),
                'profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'high',
                'prompt_profile' => 'security',
            ]],
            'limits' => config('ai6.project_config.server_defaults.limits'),
            'push_mode' => 'manual',
            'confirm_snapshot' => '1',
            'expected_approval_snapshot_hash' => str_repeat('a', 64),
        ];
    }

    private function selection(): ApprovalSelection
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(),
                'profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'high',
                'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(
                config('ai6.project_config.server_defaults.limits'),
                $this->app->make(AgentInputLimits::class),
            ),
            null,
            'manual',
        );
    }
}
