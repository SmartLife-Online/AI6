<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\CompletionReportService;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunType;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-12 of AI6-040 for the run observation half: an entitled member sees the
 * bound review subject, the stored completion report and the confirmation
 * control of a review-only run over the real route; an outsider does not.
 */
final class ReviewOnlyTimelineTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    private const STRICT_POLICY = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
        ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['ai6.run_artifacts.root' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-040-timeline-'.bin2hex(random_bytes(8))]);
        $this->app->forgetInstance(RunArtifactRoot::class);
        $this->app->forgetInstance(RunArtifactStore::class);
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

    public function test_an_entitled_member_sees_the_bound_review_subject_report_and_confirmation(): void
    {
        $attention = $this->createUser(['email' => 'attention-timeline@example.test']);
        $fixture = $this->completedApproval('AI6-040-TIMELINE', null, null, $attention);
        $run = $this->boundReviewOnlyRun($fixture);
        $report = $this->app->make(CompletionReportService::class)->build($run);
        $request = $this->app->make(HumanRequestService::class)->openManualReportRequest($run->fresh() ?? $run);

        $response = $this->actingAs($fixture['operator'])
            ->get(route('projects.runs.show', [$fixture['project'], $run->id]));

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
        $response->assertSee('Gebundener Reviewgegenstand');
        $response->assertSee(ReviewSubjectKind::MANAGED_BRANCH->value);
        $response->assertSee((string) $run->review_subject_base_sha);
        $response->assertSee((string) $run->review_subject_source_sha);
        $response->assertSee((string) $run->checkpoint_tree_sha);
        $response->assertSee('data-completion-report="'.$report->id.'"', false);
        $response->assertSee($report->digest);
        $response->assertSee(route('projects.human-requests.show', [$fixture['project'], $request]), false);
        $response->assertSee('Abschlussbericht prüfen und bestätigen');

        $body = (string) $response->getContent();
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $body);
        self::assertSame(
            0,
            preg_match('/<table|width\s*:\s*\d{4,}px/i', $body),
            'No fixed wide layout may force horizontal page scrolling.',
        );
        self::assertSame(0, preg_match('/<style|\sstyle\s*=/i', $body), 'The page must contain no inline style.');
    }

    public function test_a_user_without_a_project_membership_is_refused(): void
    {
        $fixture = $this->completedApproval('AI6-040-TIMELINE-DENY');
        $run = $this->boundReviewOnlyRun($fixture);
        $outsider = $this->createUser();

        $this->actingAs($outsider)
            ->get(route('projects.runs.show', [$fixture['project'], $run->id]))
            ->assertForbidden();
    }

    /**
     * A review-only run with its ref-free checkpoint bound through the shipped
     * orchestrator seam, so the timeline reads the real persisted binding.
     *
     * @param  array{operator: User, project: mixed, approval: mixed}  $fixture
     */
    private function boundReviewOnlyRun(array $fixture): Run
    {
        $run = $this->finalizedRun($fixture);
        self::assertSame(RunType::REVIEW_ONLY, $run->run_type);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->bindReviewCheckpoint(
            $run,
            $run->version,
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-review-checkpoint-'.$run->id,
            ReviewSubjectKind::MANAGED_BRANCH,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
            str_repeat('e', 64),
        );
        $run = $orchestrator->transition($run, $run->version, RunState::RUNNING, RunPhase::CHECK);
        $orchestrator->materializeReviewSlots($run);

        return $orchestrator->advancePhase($run, $run->version, RunPhase::REVIEW);
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
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
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), $this->app->make(AgentInputLimits::class)),
            $attentionUser?->getKey(),
            'manual',
            RunType::REVIEW_ONLY,
            'checkpoint:server-bound',
            ReviewOnlyCompletionMode::MANUAL,
        );
    }
}
