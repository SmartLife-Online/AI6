<?php

namespace Tests\Feature\Reviews;

use App\AI6\Agents\AgentScenario;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Auth\StepUpRequiredException;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\EffectiveFindingState;
use App\AI6\Reviews\FindingDispositionController;
use App\AI6\Reviews\FindingDispositionType;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingDisposition;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunTimelinePage;
use App\AI6\Runs\RunTransitionConflict;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Tickets\TicketUiTestCase;

final class FindingDispositionTest extends TicketUiTestCase
{
    use BuildsReviewRoundFixture;

    public function test_human_override_requires_approver_fresh_step_up_reason_and_current_run_version(): void
    {
        config(['logging.default' => 'null']);
        $prepared = $this->preparedReviewRun('AI6-024-DISPOSITION');
        $this->reviewAdapter([$this->reviewSlotIds[0] => AgentScenario::UNTRUSTED_EVIDENCE]);
        $this->executeReview($prepared['run']);
        $run = $prepared['run']->fresh();
        $finding = Finding::query()->where('run_id', $run->id)->firstOrFail();
        $original = $finding->only(['severity', 'original_disposition', 'title', 'evidence', 'expected_result']);
        $approver = $this->member($run->project_id, ProjectRole::APPROVER);

        $page = $this->actingAs($approver)->get(route('projects.runs.show', [$run->project()->firstOrFail(), $run->id]));
        $page->assertOk()->assertSee('Findings und AC-Abdeckung')->assertSee('<script>review-title</script>')
            ->assertSee('<script>instruction-title</script>')
            ->assertDontSee('<script>review-title</script>', false)
            ->assertDontSee('<script>instruction-title</script>', false)
            ->assertDontSee('review-secret')
            ->assertDontSee('instruction-secret')
            ->assertSee('data-original-disposition="must_fix"', false)
            ->assertSee('data-effective-disposition="must_fix"', false)
            ->assertSee('data-blocking="1"', false)
            ->assertSee('name="expected_version"', false)
            ->assertSee('wire:ignore', false)
            ->assertSee('wire:key="finding-'.$finding->id.'"', false)
            ->assertSee('form="finding-disposition-'.$finding->id.'"', false);
        $policy = (string) $page->headers->get('Content-Security-Policy');
        self::assertNotSame('', $policy);
        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        $this->actingAs($approver)->get(route('projects.runs.show', [$run->project()->firstOrFail(), $run->id]).'?reviewerFilter='.$this->reviewSlotIds[1])
            ->assertOk()->assertDontSee('<script>review-title</script>');
        $this->actingAs($approver)->get(route('projects.runs.show', [$run->project()->firstOrFail(), $run->id]).'?reviewerFilter='.$this->reviewSlotIds[0].'&dispositionFilter=must_fix')
            ->assertOk()->assertSee('<script>review-title</script>');

        try {
            $this->controller()->store($this->request($approver, [
                'disposition' => 'fixed',
                'reason' => 'Freie Panelwahl.',
                'expected_version' => $run->version,
            ]), $run->project()->firstOrFail(), $run->id, $finding->id, $this->app->make(StepUpGuard::class), $this->app->make(RunOrchestrator::class));
            self::fail('The panel accepted fixed as a free disposition.');
        } catch (ValidationException) {
        }
        self::assertDatabaseCount('finding_dispositions', 0);

        $operator = $this->member($run->project_id, ProjectRole::OPERATOR);
        $this->actingAs($operator)->get(route('projects.runs.show', [$run->project()->firstOrFail(), $run->id]))
            ->assertOk()->assertDontSee('name="disposition"', false);
        $this->actingAs($operator)->post(route('projects.runs.findings.disposition', [
            $run->project()->firstOrFail(), $run->id, $finding->id,
        ]), [
            'disposition' => 'accepted_risk',
            'reason' => 'Nicht autorisierter Routenaufruf.',
            'expected_version' => $run->version,
        ])->assertForbidden();
        self::assertDatabaseCount('finding_dispositions', 0);
        $unauthorized = $this->request($operator, [
            'disposition' => 'accepted_risk',
            'reason' => 'Nicht autorisierter Versuch.',
            'expected_version' => $run->version,
        ]);
        $this->app->make(StepUpGuard::class)->markSatisfied($unauthorized, $operator, FindingDispositionController::STEP_UP_ACTION);
        try {
            $this->controller()->store($unauthorized, $run->project()->firstOrFail(), $run->id, $finding->id, $this->app->make(StepUpGuard::class), $this->app->make(RunOrchestrator::class));
            self::fail('An unauthorized project role disposed a finding.');
        } catch (ValidationException) {
        }
        self::assertDatabaseCount('finding_dispositions', 0);

        $missing = $this->request($approver, [
            'disposition' => 'not_applicable',
            'reason' => 'Der Befund betrifft den freigegebenen Vertrag nicht.',
            'expected_version' => $run->version,
        ]);
        try {
            $this->controller()->store($missing, $run->project()->firstOrFail(), $run->id, $finding->id, $this->app->make(StepUpGuard::class), $this->app->make(RunOrchestrator::class));
            self::fail('A missing step-up proof was accepted.');
        } catch (StepUpRequiredException) {
        }
        self::assertDatabaseCount('finding_dispositions', 0);

        // The success case runs over the registered route so authorization middleware,
        // route model binding and the redirect are proven, not only the controller body.
        $this->disposeOverHttp($approver, $run, $finding, [
            'disposition' => 'not_applicable',
            'reason' => 'Der Befund betrifft den freigegebenen Vertrag nicht.',
            'expected_version' => $run->version,
        ])->assertRedirect(route('projects.runs.show', [$run->project()->firstOrFail(), $run->id]));

        self::assertDatabaseCount('finding_dispositions', 1);
        self::assertSame(
            ProjectRole::APPROVER->value,
            FindingDisposition::query()->sole()->decision_role,
        );
        self::assertSame($original, $finding->fresh()->only(array_keys($original)));
        self::assertFalse($this->app->make(EffectiveFindingState::class)->blocks($finding->fresh(), $run->fresh()));
        $this->actingAs($approver)->get(route('projects.runs.show', [$run->project()->firstOrFail(), $run->id]).'?dispositionFilter=not_applicable')
            ->assertOk()->assertSee('<script>review-title</script>');
        try {
            $finding->fresh()->forceFill(['title' => 'Überschrieben'])->save();
            self::fail('An immutable original finding was updated.');
        } catch (QueryException) {
        }
        self::assertSame($original, $finding->fresh()->only(array_keys($original)));

        $duplicate = $this->request($approver, $missing->all());
        $this->app->make(StepUpGuard::class)->markSatisfied($duplicate, $approver, FindingDispositionController::STEP_UP_ACTION);
        $this->controller()->store($duplicate, $run->project()->firstOrFail(), $run->id, $finding->id, $this->app->make(StepUpGuard::class), $this->app->make(RunOrchestrator::class));
        self::assertDatabaseCount('finding_dispositions', 1);

        foreach ([
            'checkpoint_tree_sha',
            'checkpoint_diff_hash',
            'ticket_contract_sha256',
            'config_hash',
            'scope_hash',
            'prompt_hash',
            'instruction_hash',
            'runtime_profile_hash',
            'agent_profile_hash',
            'security_policy_hash',
        ] as $field) {
            $drifted = $run->fresh();
            $drifted->setAttribute($field, str_repeat('f', 64));
            self::assertTrue(
                $this->app->make(EffectiveFindingState::class)->blocks($finding->fresh(), $drifted),
                $field.' did not invalidate the disposition.',
            );
        }
        $changedReviewer = $finding->fresh();
        $changedReviewer->provider_profile = 'different-provider';
        self::assertTrue($this->app->make(EffectiveFindingState::class)->blocks($changedReviewer, $run->fresh()));
        self::assertDatabaseCount('finding_dispositions', 1);

        $evidence = ReviewResult::query()->where('run_id', $run->id)
            ->where('result_status', 'nothing_to_fix')->firstOrFail();
        $current = $run->fresh();
        try {
            $this->app->make(RunOrchestrator::class)->recordFixedFinding(
                $current,
                $finding->fresh(),
                $evidence,
                $current->version,
                'Ein Ergebnis auf demselben Checkpoint belegt keinen Fix.',
            );
            self::fail('Same-checkpoint evidence was accepted as a fixed disposition.');
        } catch (RunTransitionConflict $exception) {
            self::assertSame('fixed_evidence_invalid', $exception->reason);
        }
        self::assertDatabaseCount('finding_dispositions', 1);

        $current = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $current,
            $current->version,
            str_repeat('4', 64),
            str_repeat('5', 64),
            str_repeat('6', 64),
        );
        $evidence = ReviewResult::query()->create([
            ...$evidence->only([
                'run_id', 'slot_id', 'role', 'provider_profile', 'model', 'effort', 'prompt_profile', 'session_id',
                'approval_config_hash', 'approval_scope_hash', 'approval_prompt_hash', 'approval_instruction_hash',
                'approval_runtime_profile_hash', 'approval_agent_profile_hash', 'approval_security_policy_hash',
                'approval_snapshot_hash', 'slot_prompt_hash', 'slot_instruction_hash', 'slot_runtime_profile_hash',
                'workspace_tree_hash', 'raw_artifact_id',
            ]),
            'id' => (string) Str::uuid(),
            'round_number' => 2,
            'attempt' => 1,
            'checkpoint_commit_sha' => $current->checkpoint_commit_sha,
            'checkpoint_tree_sha' => $current->checkpoint_tree_sha,
            'diff_hash' => $current->checkpoint_diff_hash,
            'invocation_outcome' => 'valid_result',
            'failure_code' => null,
            'result_status' => 'nothing_to_fix',
        ]);
        // The checkpoint drift was persisted through the one CAS boundary: the earlier
        // not_applicable disposition is invalid, yet remains fully readable as history.
        self::assertTrue($this->app->make(EffectiveFindingState::class)->blocks($finding->fresh(), $current));
        self::assertNull($this->app->make(EffectiveFindingState::class)->currentDisposition($finding->fresh(), $current));
        $superseded = FindingDisposition::query()->where('type', FindingDispositionType::NOT_APPLICABLE->value)->sole();
        self::assertSame('Der Befund betrifft den freigegebenen Vertrag nicht.', $superseded->reason);
        self::assertSame($approver->getKey(), $superseded->decided_by);
        $afterFixed = $this->app->make(RunOrchestrator::class)->recordFixedFinding(
            $current,
            $finding->fresh(),
            $evidence,
            $current->version,
            'Unabhängiges Ergebnis bestätigt den neuen Checkpoint.',
        );
        self::assertSame(
            FindingDispositionType::FIXED,
            $this->app->make(EffectiveFindingState::class)->currentDisposition($finding->fresh(), $afterFixed)?->type,
        );
        self::assertFalse($this->app->make(EffectiveFindingState::class)->blocks($finding->fresh(), $afterFixed));
        self::assertDatabaseCount('finding_dispositions', 2);

        $acceptedRisk = $this->request($approver, [
            'disposition' => 'accepted_risk',
            'reason' => 'Das dokumentierte Restrisiko wird bewusst übernommen.',
            'expected_version' => $afterFixed->version,
        ]);
        $this->app->make(StepUpGuard::class)->markSatisfied($acceptedRisk, $approver, FindingDispositionController::STEP_UP_ACTION);
        $this->controller()->store($acceptedRisk, $run->project()->firstOrFail(), $run->id, $finding->id, $this->app->make(StepUpGuard::class), $this->app->make(RunOrchestrator::class));
        self::assertSame(
            FindingDispositionType::ACCEPTED_RISK,
            $this->app->make(EffectiveFindingState::class)->currentDisposition($finding->fresh(), $afterFixed->fresh())?->type,
        );
        self::assertDatabaseCount('finding_dispositions', 3);

        $stale = $this->request($approver, [
            'disposition' => 'accepted_risk',
            'reason' => 'Bewusstes Restrisiko.',
            'expected_version' => $run->version,
        ]);
        $this->app->make(StepUpGuard::class)->markSatisfied($stale, $approver, FindingDispositionController::STEP_UP_ACTION);
        $this->expectException(ValidationException::class);
        try {
            $this->controller()->store($stale, $run->project()->firstOrFail(), $run->id, $finding->id, $this->app->make(StepUpGuard::class), $this->app->make(RunOrchestrator::class));
        } finally {
            self::assertSame(3, FindingDisposition::query()->count());
        }
    }

    /** TC-11 and TC-12: the panel stays bound to its finding across filtering and on smartphone width. */
    public function test_the_findings_panel_stays_bound_across_filtering_and_on_smartphone_width(): void
    {
        $prepared = $this->preparedReviewRun('AI6-024-PANEL');
        $this->reviewAdapter([
            $this->reviewSlotIds[0] => AgentScenario::UNTRUSTED_EVIDENCE,
            $this->reviewSlotIds[1] => AgentScenario::FINDINGS,
        ]);
        $this->executeReview($prepared['run']);
        $run = $prepared['run']->fresh();
        $project = $run->project()->firstOrFail();
        $approver = $this->member($run->project_id, ProjectRole::APPROVER);
        $findings = Finding::query()->where('run_id', $run->id)->orderBy('slot_id')->get();
        self::assertCount(2, $findings);
        [$first, $second] = [$findings[0], $findings[1]];

        $this->actingAs($approver);
        $component = Livewire::test(RunTimelinePage::class, ['project' => $project, 'runId' => $run->id]);
        foreach ([$first, $second] as $finding) {
            $component->assertSee('wire:key="finding-'.$finding->id.'"', false)
                ->assertSee('id="finding-disposition-'.$finding->id.'"', false);
        }

        // Filtering removes a list entry; every remaining form must still address its own finding.
        $filtered = $component->set('reviewerFilter', $second->slot_id)->html();
        self::assertStringContainsString('wire:key="finding-'.$second->id.'"', $filtered);
        self::assertStringNotContainsString('finding-disposition-'.$first->id, $filtered);
        self::assertSame(1, substr_count($filtered, 'id="finding-disposition-'.$second->id.'"'));
        self::assertSame(1, substr_count($filtered, 'form="finding-disposition-'.$second->id.'"'));
        self::assertStringContainsString(
            route('projects.runs.findings.disposition', [$project, $run->id, $second->id]),
            $filtered,
        );

        $body = (string) $this->actingAs($approver)
            ->get(route('projects.runs.show', [$project, $run->id]))->getContent();
        self::assertStringContainsString('Findings und AC-Abdeckung', $body);
        self::assertStringContainsString($second->duplicate_group, $body);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $body);
        self::assertSame(0, preg_match('/<table|width\s*:\s*\d{4,}px/i', $body), 'No fixed wide layout may force horizontal scrolling.');
        // The rendered 64-character hashes must wrap instead of widening the page.
        $stylesheet = (string) file_get_contents(dirname(__DIR__, 3).'/public/assets/ai6.css');
        self::assertMatchesRegularExpression('/body\s*\{[^}]*overflow-wrap:\s*anywhere/s', $stylesheet);
    }

    private function controller(): FindingDispositionController
    {
        return new FindingDispositionController;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return TestResponse<Response>
     */
    private function disposeOverHttp(User $actor, Run $run, Finding $finding, array $parameters): TestResponse
    {
        $this->actingAs($actor);
        $this->startSession();
        $session = $this->app->make('session')->driver();
        $proof = Request::create('/finding-disposition', 'POST');
        $proof->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied($proof, $actor, FindingDispositionController::STEP_UP_ACTION);
        $session->save();

        return $this->withCookie((string) config('session.cookie'), $session->getId())
            ->post(route('projects.runs.findings.disposition', [
                $run->project()->firstOrFail(), $run->id, $finding->id,
            ]), $parameters);
    }

    /** @param array<string, mixed> $parameters */
    private function request(User $actor, array $parameters): Request
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('finding-disposition-'.$actor->id.'-'.bin2hex(random_bytes(4)));
        $session->start();
        $request = Request::create('/finding-disposition', 'POST', $parameters);
        $request->setLaravelSession($session);
        $request->setUserResolver(static fn (): User => $actor);

        return $request;
    }

    private function member(int $projectId, ProjectRole $role): User
    {
        return ProjectMembership::query()->where('project_id', $projectId)->where('role', $role->value)
            ->firstOrFail()->user()->firstOrFail();
    }
}
