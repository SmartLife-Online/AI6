<?php

namespace Tests\Feature\Runs;

use App\AI6\Projects\ProjectAction;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\CheckpointDiffRecorder;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Shared\Redaction\RedactionMatchType;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * AI6-031 TC-09 and TC-15: the artifact download is an authorization surface
 * bound to project, run and artifact, hands out redacted stored bytes only,
 * without inline rendering, and leaves the authorization inventory unchanged.
 */
final class RunArtifactDownloadTest extends TicketUiTestCase
{
    use BuildsObservedRunFixture;

    /** TC-09 */
    public function test_only_an_entitled_member_receives_the_redacted_bytes_as_an_attachment(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC09');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, "{\"raw\":\"password=hunter2\",\"note\":\"sichtbar\"}\n");
        $url = route('projects.runs.artifacts.download', [$project, $run->id, $artifact->id]);
        $stranger = $this->createUser();
        $outsider = $this->createUser();
        $foreignProject = $this->createProject('Fremdprojekt '.bin2hex(random_bytes(4)));
        $this->addMembership($outsider, $foreignProject, ProjectRole::ADMIN);

        // Unauthenticated: no bytes, the login barrier answers.
        $this->get($url)->assertRedirect(route('login'));

        // Authenticated without the project entitlement: forbidden, no bytes.
        $unauthorized = $this->actingAs($stranger)->get($url);
        $unauthorized->assertForbidden();
        self::assertStringNotContainsString('sichtbar', (string) $unauthorized->getContent());

        // Foreign reference: the artifact under another project's URL, and a foreign artifact under this run.
        $this->actingAs($outsider)
            ->get(route('projects.runs.artifacts.download', [$foreignProject, $run->id, $artifact->id]))
            ->assertNotFound();
        $this->actingAs($operator)
            ->get(route('projects.runs.artifacts.download', [$project, $run->id, (string) Str::uuid()]))
            ->assertNotFound();

        // Entitled: exactly the stored redacted bytes, as an attachment, never inline.
        $response = $this->actingAs($operator)->get($url);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertHeader('Content-Disposition', 'attachment; filename="run-artifact-'.$artifact->sequence.'-provider_raw.txt"');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $body = (string) $response->getContent();
        self::assertSame($this->app->make(RunArtifactStore::class)->bytes($artifact), $body);
        self::assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        self::assertStringContainsString('password='.RedactionMatchType::SECRET->marker(), $body);
        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringContainsString('sichtbar', $body);
        self::assertSame(0, DB::table('jobs')->count(), 'A download starts nothing.');
    }

    /** TC-09: a foreign artifact of another run in another project is refused even for a member of both. */
    public function test_an_artifact_of_another_run_is_refused_under_this_run(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC09-A');
        [$otherRun, $otherProject] = $this->secondObservedRun('AI6-031-TC09-B');
        $this->addMembership($operator, $otherProject, ProjectRole::OPERATOR);
        $foreign = $this->storeObservedArtifact($otherRun, RunArtifactKind::PROVIDER_RAW, '{"raw":"fremd"}');

        $this->actingAs($operator)
            ->get(route('projects.runs.artifacts.download', [$project, $run->id, $foreign->id]))
            ->assertNotFound();
        $this->actingAs($operator)
            ->get(route('projects.runs.artifacts.download', [$otherProject, $otherRun->id, $foreign->id]))
            ->assertOk();
    }

    /** TC-09: expired, deleted and oversized references yield no bytes. */
    public function test_expired_deleted_and_oversized_artifacts_are_refused_deterministically(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC09-R');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, '{"changed_files":[],"decisions":[],"marker":"'.str_repeat('x', 300).'"}');
        $url = route('projects.runs.artifacts.download', [$project, $run->id, $artifact->id]);

        $this->actingAs($operator)->get($url)->assertOk();

        // The smallest artifact budget the policy admits is still below this artifact.
        config(['ai6.retention.artifacts.max_bytes' => CheckpointDiffRecorder::HEADER_MAX_BYTES]);
        $oversized = $this->actingAs($operator)->get($url);
        $oversized->assertStatus(413);
        self::assertStringNotContainsString('changed_files', (string) $oversized->getContent());
        config(['ai6.retention.artifacts.max_bytes' => 20000000]);

        Date::setTestNow('2026-10-03 12:00:00');
        $expired = $this->actingAs($operator)->get($url);
        $expired->assertStatus(410);
        self::assertStringNotContainsString('changed_files', (string) $expired->getContent());

        self::assertTrue($this->app->make(RunArtifactStore::class)->purge($artifact->fresh() ?? $artifact, Date::now()));
        $deleted = $this->actingAs($operator)->get($url);
        $deleted->assertStatus(410);
        self::assertStringNotContainsString('changed_files', (string) $deleted->getContent());
        self::assertNull(RunArtifact::query()->findOrFail($artifact->id)->storage_reference);
    }

    /** TC-15 */
    public function test_the_download_route_is_authenticated_and_authorized_by_the_existing_view_run_permission(): void
    {
        $route = Route::getRoutes()->getByName('projects.runs.artifacts.download');
        self::assertNotNull($route);
        self::assertSame(['GET', 'HEAD'], $route->methods());
        self::assertSame('projects/{project}/runs/{runId}/artifacts/{artifactId}', $route->uri());
        self::assertContains('auth', $route->middleware());
        self::assertContains('can:viewRun,project', $route->middleware());
        self::assertNotContains('can:disposeFinding,project', $route->middleware());

        // The closed authorization set stays exactly as before: no new action was introduced.
        self::assertSame(
            ['appear_in_list', 'view_details', 'refresh_read_model', 'edit_ticket', 'change_ticket_status', 'refresh_configuration', 'approve_configuration', 'approve_ticket', 'authorize_gate_evidence', 'start_run', 'view_run', 'answer_human_request', 'intervene_run', 'dispose_finding'],
            array_map(static fn (ProjectAction $action): string => $action->value, ProjectAction::cases()),
        );

        [$run, $project, $operator] = $this->observedRun('AI6-031-TC15');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"berechtigt"}');
        $url = route('projects.runs.artifacts.download', [$project, $run->id, $artifact->id]);
        $viewer = $this->createUser();
        $this->addMembership($viewer, $project, ProjectRole::VIEWER);
        $nonMember = $this->createUser();

        $this->actingAs($operator)->get($url)->assertOk();
        $this->actingAs($viewer)->get($url)->assertOk();
        $refused = $this->actingAs($nonMember)->get($url);
        $refused->assertForbidden();
        self::assertStringNotContainsString('berechtigt', (string) $refused->getContent());
    }
}
