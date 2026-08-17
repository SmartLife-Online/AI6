<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
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

    /** TC-10: the base page shows no detail area that AI6-031 only adds later. */
    public function test_the_base_page_does_not_fake_the_later_detail_areas(): void
    {
        [$run, $project, $operator] = $this->preparedRun('AI6-017-UI-8');

        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        foreach (['Diff', 'Checks', 'Findings', 'Security-Gate', 'Pushstatus', 'Interventionen'] as $later) {
            $response->assertDontSee($later);
        }
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
