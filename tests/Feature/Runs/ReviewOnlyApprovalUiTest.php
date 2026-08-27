<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\Git\ReviewSubjectReference;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\BuildTicketApprovalPreview;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\Models\TicketApprovalPreview;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunType;
use App\AI6\Runs\TicketApprovalController;
use App\AI6\Runs\TicketApprovalPage;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Git\BuildsRunWorkspaceGitFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-12 of AI6-040: the panel binds a review subject before the claim, the
 * start action takes no source of its own, every effect stays asynchronous, and
 * the fixed CSP is unchanged.
 */
final class ReviewOnlyApprovalUiTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;
    use BuildsReviewOnlyRunFixture;
    use BuildsRunWorkspaceGitFixture;

    private const STRICT_POLICY = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
        ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        return $this->reviewOnlySelection($attentionUser);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @param list<string> $ticketFiles */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_the_panel_binds_a_review_subject_before_the_claim_and_starts_it_asynchronously(): void
    {
        $managed = $this->prepareManagedReviewProject('AI6-040-UI');
        $project = $managed['project'];
        $approver = $this->createUser();
        $operator = $this->createUser();
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $this->addMembership($operator, $project, ProjectRole::OPERATOR);
        $content = $this->validTicketMarkdown('AI6-040-UI');
        $readModel = $this->publishReadModel($managed['administrator'], $project, 'tickets/AI6-040-UI.md', $content);

        [$component, $preview] = $this->readyPreview($approver, $project, $readModel, $managed['source']);
        $payload = $this->approvalPayload($component, $preview, $readModel, $content, $managed['source']);

        $page = $this->actingAs($approver)->get(route('projects.tickets.approval', [$project, $readModel]));
        $page->assertOk();
        $page->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
        $page->assertSee('Nur Review');
        // The bound-source controls appear once the human selects the run type.
        $component->assertSee('Gebundene Basis-OID');
        $component->assertSee('Verwaltete Ref');

        // A user without the approval role never reaches the bound source.
        $this->actingAs($operator)
            ->post(route('projects.tickets.approval.store', [$project, $readModel]), $payload)
            ->assertForbidden();
        self::assertSame(0, TicketApproval::query()->count());

        $this->actingAs($approver);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($approver);
        $this->post(route('auth.step-up.totp.verify', ['action' => TicketApprovalController::STEP_UP_ACTION]), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $this->post(route('projects.tickets.approval.store', [$project, $readModel]), $payload)->assertRedirect();

        $approval = TicketApproval::query()->sole();
        self::assertSame(RunType::REVIEW_ONLY, $approval->run_type);
        self::assertSame(ReviewOnlyCompletionMode::MANUAL, $approval->completion_mode);
        $subject = $this->app->make(ReviewSubjectReference::class)->decode(
            (string) $approval->review_subject_reference,
            new RedactionContext((string) $project->id, null, 'review-only-ui-test'),
        );
        self::assertSame(ReviewSubjectKind::MANAGED_BRANCH, $subject->kind);
        self::assertSame($managed['base'], $subject->baseOid);
        self::assertSame($managed['source'], $subject->sourceOid);
        // The browser request queued the mutation only: no run, no step, no
        // agent, no Git and no check ran inside it.
        self::assertSame(0, Run::query()->count());
        self::assertSame(0, DB::table('execution_jobs')->count());
        self::assertSame(1, ControlOperation::query()->where('operation_type', ControlOperationType::TICKET_APPROVAL)->count());
    }

    public function test_the_start_action_refuses_a_source_of_its_own(): void
    {
        $prepared = $this->preparedReviewOnlyRunApproval('AI6-040-UI-START');

        $this->actingAs($prepared['operator'])
            ->post(route('projects.approvals.start', [$prepared['project'], $prepared['approval']]), [
                'review_subject_kind' => 'managed_branch',
                'review_source_oid' => str_repeat('c', 64),
            ])
            ->assertSessionHasErrors('run');
        self::assertSame(0, ControlOperation::query()->where('operation_type', ControlOperationType::RUN_START)->count());

        $this->actingAs($prepared['operator'])
            ->post(route('projects.approvals.start', [$prepared['project'], $prepared['approval']]))
            ->assertRedirect();
        $start = ControlOperation::query()->where('operation_type', ControlOperationType::RUN_START)->sole();
        self::assertSame(
            'review_only',
            json_decode($start->operation_parameters_jcs, true, flags: JSON_THROW_ON_ERROR)['run_type'] ?? null,
        );
        // The claim itself stays with the worker: the browser produced an
        // operation, never a run or an execution step.
        self::assertSame(0, Run::query()->count());
        self::assertSame(0, DB::table('execution_jobs')->count());
    }

    public function test_a_drifted_source_is_refused_with_its_own_preview_reason(): void
    {
        $managed = $this->prepareManagedReviewProject('AI6-040-UI-DRIFT');
        $project = $managed['project'];
        $approver = $this->createUser();
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $content = $this->validTicketMarkdown('AI6-040-UI-DRIFT');
        $readModel = $this->publishReadModel($managed['administrator'], $project, 'tickets/AI6-040-UI-DRIFT.md', $content);

        Livewire::actingAs($approver);
        $component = $this->reviewOnlyComponent($project, $readModel, $managed['source']);
        // The managed branch moves between the selection and the worker-side
        // verification of the preview.
        $this->managedReviewCommit($managed['repository'], 'app/Example.php', "<?php\n\n// drifted\n", 'drifted');
        $previewId = $component->get('previewId');
        self::assertIsString($previewId);
        $this->app->call([new BuildTicketApprovalPreview($previewId), 'handle']);

        $preview = TicketApprovalPreview::query()->findOrFail($previewId);
        self::assertSame('conflict', $preview->state);
        self::assertSame('review_source_drift:managed_branch_drift', $preview->error_code);
        self::assertSame(0, TicketApproval::query()->count());
    }

    /** @return array{project: Project, approval: TicketApproval, operator: User} */
    private function preparedReviewOnlyRunApproval(string $ticketId): array
    {
        $managed = $this->prepareManagedReviewProject($ticketId);
        $this->reviewOnlySubject = $this->reviewSubjectFor(
            ReviewSubjectKind::MANAGED_BRANCH,
            $managed['base'],
            $managed['source'],
        );
        $fixture = $this->completedApproval($ticketId, $managed['project']->refresh(), $managed['operator']);
        DB::table('jobs')->delete();

        return ['project' => $fixture['project'], 'approval' => $fixture['approval'], 'operator' => $fixture['operator']];
    }

    /** @return array{0: Testable<TicketApprovalPage>, 1: TicketApprovalPreview} */
    private function readyPreview(User $approver, Project $project, TicketReadModel $readModel, string $source): array
    {
        Livewire::actingAs($approver);
        $component = $this->reviewOnlyComponent($project, $readModel, $source);
        $previewId = $component->get('previewId');
        self::assertIsString($previewId);
        $this->app->call([new BuildTicketApprovalPreview($previewId), 'handle']);
        DB::table('jobs')->delete();
        $preview = TicketApprovalPreview::query()->findOrFail($previewId);
        self::assertSame('ready', $preview->state, (string) $preview->error_code);

        return [$component, $preview];
    }

    /** @return Testable<TicketApprovalPage> */
    private function reviewOnlyComponent(Project $project, TicketReadModel $readModel, string $source): Testable
    {
        return Livewire::test(TicketApprovalPage::class, [
            'project' => $project,
            'readModel' => (string) $readModel->getKey(),
        ])->set('implementationProfile', 'fake')
            ->set('reviewerInputs.0.profile', 'fake')
            ->set('runType', 'review_only')
            ->set('reviewSubjectKind', ReviewSubjectKind::MANAGED_BRANCH->value)
            ->set('reviewSourceRef', 'refs/heads/main')
            ->set('reviewSourceOid', $source)
            ->set('completionMode', ReviewOnlyCompletionMode::MANUAL->value)
            ->call('requestPreview');
    }

    /**
     * @param  Testable<TicketApprovalPage>  $component
     * @return array<string, mixed>
     */
    private function approvalPayload(
        Testable $component,
        TicketApprovalPreview $preview,
        TicketReadModel $readModel,
        string $content,
        string $source,
    ): array {
        return [
            'operation_id' => $component->get('operationId'),
            'preview_id' => $preview->id,
            'expected_control_oid' => $readModel->control_commit,
            'expected_blob' => $readModel->blob_sha,
            'base_content' => $content,
            'reason' => 'Menschliche Freigabe des Reviewgegenstands',
            'implementation_profile' => $component->get('implementationProfile'),
            'implementation_model' => $component->get('implementationModel'),
            'implementation_effort' => $component->get('implementationEffort'),
            'reviewers' => $component->get('reviewerInputs'),
            'limits' => $component->get('limitInputs'),
            'attention_user_id' => null,
            'push_mode' => $component->get('pushMode'),
            'run_type' => 'review_only',
            'review_subject_kind' => ReviewSubjectKind::MANAGED_BRANCH->value,
            'review_base_oid' => $readModel->control_commit,
            'review_source_oid' => $source,
            'review_source_ref' => 'refs/heads/main',
            'completion_mode' => ReviewOnlyCompletionMode::MANUAL->value,
            'confirm_snapshot' => '1',
            'expected_approval_snapshot_hash' => $preview->approval_snapshot_hash,
        ];
    }
}
