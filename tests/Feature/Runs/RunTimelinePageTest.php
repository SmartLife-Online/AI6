<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunTimelinePage;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Tickets\TicketUiTestCase;

final class RunTimelinePageTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    private const STRICT_POLICY = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
        ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

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

    /** TC-10: an entitled member sees the timeline, the polling stays a read access. */
    public function test_an_entitled_member_sees_the_base_timeline(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-017-UI-1');

        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        $response->assertSee('Run-Timeline');
        $response->assertSee($run->id);
        $response->assertSee('data-step-type="preflight" data-step-state="succeeded"', false);
        $response->assertSee('data-step-type="implement" data-step-state="planned"', false);
        $response->assertSee('data-run-state="running"', false);
        $response->assertSee('data-run-phase="implement"', false);
        $response->assertSee('wire:poll.2s', false);
        $response->assertSee('step.preflight.succeeded');
        self::assertSame(0, DB::table('jobs')->count(), 'A browser request starts no process, Git or agent work.');
    }

    /** TC-10: an unentitled user gets no run content. */
    public function test_a_non_member_receives_no_run_content(): void
    {
        [$run, $project] = $this->preparedRun('AI6-017-UI-2');
        $stranger = $this->createUser();

        $response = $this->actingAs($stranger)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertForbidden();
        $response->assertDontSee($run->id);
        $response->assertDontSee($project->name);
    }

    /** TC-10: a member of another project cannot read a foreign run through his own project. */
    public function test_a_run_of_another_project_is_not_readable(): void
    {
        [$run] = $this->preparedRun('AI6-017-UI-3');
        $outsider = $this->createUser();
        $ownProject = $this->createProject('Fremdprojekt '.bin2hex(random_bytes(4)));
        $this->addMembership($outsider, $ownProject, ProjectRole::ADMIN);

        $this->actingAs($outsider)
            ->get(route('projects.runs.show', [$ownProject, $run->id]))
            ->assertNotFound();
    }

    /** TC-10: an unknown run id is refused after the policy, not before it. */
    public function test_authorization_decides_before_the_run_is_looked_up(): void
    {
        [, $project] = $this->preparedRun('AI6-017-UI-4');
        $stranger = $this->createUser();
        $unknownRun = (string) Str::uuid();

        $this->actingAs($stranger)
            ->get(route('projects.runs.show', [$project, $unknownRun]))
            ->assertForbidden();
    }

    /** TC-12: the page loads the external bundle only and leaves the fixed CSP untouched. */
    public function test_the_page_stays_inside_the_fixed_content_security_policy(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-017-UI-5');

        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
        $body = $response->getContent();
        self::assertIsString($body);
        self::assertSame(0, preg_match('/<script(?![^>]*\ssrc=)/i', $body), 'The page must contain no inline script.');
        self::assertSame(0, preg_match('/\son[a-z]+\s*=/i', $body), 'The page must contain no event handler attribute.');
        self::assertSame(0, preg_match('/<style|\sstyle\s*=/i', $body), 'The page must contain no inline style.');
        self::assertStringNotContainsString('unsafe-inline', (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringNotContainsString('unsafe-eval', (string) $response->headers->get('Content-Security-Policy'));
        foreach (self::scriptSources($body) as $source) {
            self::assertStringStartsWith('http://localhost/assets/', $source, 'Only the same-origin bundle under /assets/ may be loaded.');
        }
    }

    /** TC-12: the layout keeps the responsive meta and the single external stylesheet. */
    public function test_the_page_stays_usable_on_smartphone_width(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-017-UI-6');

        $body = (string) $this->actingAs($operator)
            ->get(route('projects.runs.show', [$project, $run->id]))->getContent();

        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $body);
        self::assertStringContainsString('/assets/ai6.css', $body);
        self::assertSame(0, preg_match('/<table|width\s*:\s*\d{4,}px/i', $body), 'No fixed wide layout may force horizontal page scrolling.');
    }

    /** The navigation links the active run of the project in the route. */
    public function test_the_navigation_offers_the_active_run(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-017-UI-7');
        $url = route('projects.runs.show', [$project, $run->id]);

        $this->actingAs($operator)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee($url, false);

        DB::table('projects')->where('id', $project->getKey())->update(['active_run_id' => null]);
        $this->actingAs($operator)->get(route('projects.show', $project))
            ->assertOk()
            ->assertDontSee($url, false);
    }

    /** TC-10: the polling refresh re-authorizes and stays a read access. */
    public function test_the_polling_refresh_re_authorizes_and_only_reads(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-017-UI-9');

        $this->actingAs($operator);
        $component = Livewire::test(RunTimelinePage::class, ['project' => $project, 'runId' => $run->id]);
        $component->assertSee('step.preflight.succeeded');

        DB::table('project_memberships')->where('user_id', $operator->getKey())
            ->where('project_id', $project->getKey())->delete();

        $component->call('$refresh')->assertForbidden();
        $component->assertDontSee('step.preflight.succeeded');
    }

    /** TC-10: a prepared implement step is never delivered from a browser request. */
    public function test_the_prepared_implement_step_is_not_delivered_by_the_page(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-017-UI-10');

        $this->actingAs($operator);
        Livewire::test(RunTimelinePage::class, ['project' => $project, 'runId' => $run->id])
            ->call('$refresh');

        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(1, ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->count());
    }

    /**
     * TC-10 of AI6-017 pinned the absence of the later detail areas; AI6-031
     * added them, so the base page now carries every UI-004 area as a real
     * server-rendered section instead of a placeholder.
     */
    public function test_the_base_page_renders_the_detail_areas_added_by_ai6_031(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-017-UI-8');

        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        foreach (['data-diff', 'data-checks', 'data-security', 'data-push', 'data-human-requests', 'data-artifacts', 'data-timeline'] as $area) {
            $response->assertSee($area, false);
        }
    }

    /** AI6-020 TC-12: scope, extensions, quarantine and limit usage appear redacted and read-only. */
    public function test_the_timeline_shows_scope_extensions_quarantine_and_limit_usage_redacted(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-020-UI-1');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $canonicalJson = $this->app->make(CanonicalJson::class);
        $run = $orchestrator->applyScopeDecision($run, 'docs/allowed.md', true, null, 12, $canonicalJson, 'auto_allow');
        $run = $orchestrator->applyScopeDecision($run, 'docs/unlisted.md', true, null, 12, $canonicalJson, 'unlisted_auto_allow');
        $run = $orchestrator->applyScopeDecision($run, 'app/Amended.php', true, null, 12, $canonicalJson, 'amendment');
        $orchestrator->applyScopeDecision($run, 'docs/api_key=supersecret1234.md', false, null, 12, $canonicalJson, 'human_rejected');

        config(['ai6.run_artifacts.root' => sys_get_temp_dir().'/ai6-020-timeline-artifacts']);
        $this->app->forgetInstance(RunArtifactRoot::class);
        $this->app->forgetInstance(RunArtifactStore::class);
        $this->app->make(RunArtifactStore::class)->store(
            $run->fresh(),
            RunArtifactKind::QUARANTINED_PATH,
            json_encode(['path' => 'docs/api_key=supersecret1234.md', 'change' => 'added'], JSON_THROW_ON_ERROR),
            ['kind' => RunArtifactKind::QUARANTINED_PATH->value, 'path' => 'docs/api_key=supersecret1234.md', 'change' => 'added'],
            new RedactionContext((string) $run->project_id, $run->id, 'run-timeline-test'),
        );

        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        $response->assertSee('data-scope-decision-path="docs/allowed.md"', false);
        $response->assertSee('data-scope-decision-outcome="approved"', false);
        // The persisted reason is shown as such: an amendment adoption never
        // reads as risk-free automation, and unlisted_auto_allow stays
        // distinguishable from auto_allow.
        $response->assertSee('data-scope-decision-reason="auto_allow"', false);
        $response->assertSee('data-scope-decision-reason="unlisted_auto_allow"', false);
        $response->assertSee('data-scope-decision-reason="amendment"', false);
        $response->assertSee('data-scope-decision-reason="human_rejected"', false);
        $response->assertSee('automatisch risikoarm nach scope.auto_allow');
        $response->assertSee('nicht gelisteter Pfad nach scope.unlisted_paths');
        $response->assertSee('per Vertragsänderung aufgenommen');
        $response->assertSee('menschlich abgelehnt');
        $response->assertDontSee('unbekannter Grund');
        $response->assertSee('data-scope-limit-used="3"', false);
        $response->assertSee('Verbrauchte Zusatzpfade: 3 von 12');
        $response->assertSee('data-quarantined-change="added"', false);
        // The untrusted path is shown redacted only; the secret never renders.
        $response->assertDontSee('supersecret1234');
        // The fixed CSP stays untouched and no mutation leaves the request.
        $response->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
        self::assertSame(0, DB::table('jobs')->count());
    }

    /**
     * A run whose preflight already succeeded, so the timeline shows several step states.
     *
     * @return array{Run, Project, User}
     */
    private function preparedRun(string $ticketId): array
    {
        $fixture = $this->completedApproval($ticketId);
        $run = $this->finalizedRun($fixture);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $run = $orchestrator->bindWorkspace(
            $run,
            $run->version,
            'refs/heads/ai6/runs/'.$fixture['project']->project_identifier.'/'.$run->id,
            '/managed/worktrees/'.$run->id,
        );
        $run = $orchestrator->bindCheckpoint($run, $run->version, str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64));

        $job = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::PREFLIGHT->value)->firstOrFail();
        (new ExecuteRunStep($job->id))->handle($orchestrator);
        DB::table('jobs')->delete();

        return [$run->refresh(), $fixture['project']->refresh(), $fixture['operator']];
    }

    /** @return list<non-empty-string> */
    private static function scriptSources(string $body): array
    {
        preg_match_all('/<script[^>]*\ssrc="([^"]+)"/i', $body, $matches);

        return $matches[1];
    }
}
