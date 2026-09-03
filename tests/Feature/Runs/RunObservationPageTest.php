<?php

namespace Tests\Feature\Runs;

use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunRetentionSweep;
use App\AI6\Runs\RunTimelinePage;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * AI6-031 TC-01, TC-03, TC-04, TC-05, TC-08 and TC-14: the complete run
 * observation over the registered route, its read-only refresh, its visible
 * limits, its retention display and the permanent security notice.
 */
final class RunObservationPageTest extends TicketUiTestCase
{
    use BuildsObservedRunFixture;

    private const STRICT_POLICY = "default-src 'self'; script-src http://localhost/assets/; style-src 'self'; "
        ."img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; "
        ."base-uri 'none'; object-src 'none'; frame-ancestors 'none';";

    /** TC-01 */
    public function test_the_run_page_shows_every_ui_004_area_once_over_its_registered_route(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC01');
        $this->seedObservedCheckResult($run, "Fehler in Zeile 3\n\x1b[31mrot\x1b[0m");
        $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, json_encode([
            'changed_files' => [['path' => 'app/Example.php', 'change' => 'modified', 'bytes' => 42]],
            'decisions' => [['key' => 'd1', 'title' => 'Entscheidung', 'rationale' => 'Begründung']],
        ], JSON_THROW_ON_ERROR));
        $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"provider"}');
        $this->storeObservedCheckpointDiff($run, "diff --git a/app/Example.php b/app/Example.php\n--- a/app/Example.php\n+++ b/app/Example.php\n@@ -1 +1 @@\n-alte Zeile\n+neue Zeile password=hunter2\n");
        $step = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        $request = $this->app->make(HumanRequestService::class)->open($run->fresh(), $this->humanRequestProposal('Rückfrage zur Umsetzung'), (string) Str::uuid(), $step->idempotency_key);

        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', self::STRICT_POLICY);
        $response->assertSee('data-run-overview', false);
        $response->assertSee('data-run-type="implementation"', false);
        $response->assertSee('data-run-state="waiting"', false);
        $response->assertSee('data-run-phase="implement"', false);
        $response->assertSee('data-run-wait="human_question"', false);
        // The implementation slot materializes with the turn; before that the
        // approved model and effort are shown from the bound snapshot.
        $response->assertSee('data-implementation-slots="0"', false);
        $response->assertSee('data-implementation-planned', false);
        $response->assertSee('fake · fake-model · medium');
        $response->assertSee('data-reviewer-slots="0"', false);
        $response->assertSee('data-reviewer-planned', false);
        $response->assertSee('fake · fake-model · high · Promptprofil security');
        $response->assertSee('data-verifier-slots="0"', false);
        $response->assertSee('data-review-rounds="0"', false);
        $response->assertSee('Reviewrunden 0 von 4');
        $response->assertSee('data-push-mode="manual"', false);
        $response->assertSee('data-wait-situation="human_question"', false);
        $response->assertSee('Rückfrage zur Umsetzung');
        $response->assertSee('data-diff', false);
        $response->assertSee('data-diff-hash="'.str_repeat('3', 64).'"', false);
        $response->assertSee('data-changed-path="app/Example.php"', false);
        // The actual change lines of the bound checkpoint, redacted, as text.
        $response->assertSee('data-diff-text', false);
        $response->assertSee('-alte Zeile');
        $response->assertSee('+neue Zeile password='.RedactionMatchType::SECRET->marker());
        $response->assertDontSee('hunter2');
        $response->assertDontSee('data-truncated="diff"', false);
        $response->assertSee('data-checks', false);
        $response->assertSee('data-check-profile="php-targeted" data-check-state="failed"', false);
        $response->assertSee('data-check-output', false);
        $response->assertSee('Fehler in Zeile 3');
        $response->assertSee('data-findings-list', false);
        $response->assertSee('data-human-requests', false);
        $response->assertSee('data-human-request="'.$request->id.'"', false);
        $response->assertSee('data-security', false);
        $response->assertSee('data-security-banner', false);
        $response->assertSee('data-security-review="none"', false);
        $response->assertSee('data-push', false);
        $response->assertSee('data-branch-publication-state=""', false);
        $response->assertSee('data-queue-state="consumed"', false);
        $response->assertSee('data-artifacts', false);
        $response->assertSee('data-artifact-kind="provider_raw"', false);
        $response->assertSee('data-timeline', false);
        $response->assertSee('step.preflight.succeeded');
        $body = (string) $response->getContent();
        // The ANSI colour of the check output is a visible placeholder, never a sequence.
        self::assertStringNotContainsString("\x1b", $body);
        self::assertStringContainsString("\u{FFFD}rot\u{FFFD}", $body);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $body);
        self::assertSame(0, preg_match('/<table|width\s*:\s*\d{4,}px|<style|\sstyle\s*=/i', $body), 'No fixed layout and no inline style.');
        self::assertSame(0, DB::table('jobs')->count(), 'The page starts nothing.');
    }

    /** TC-03 */
    public function test_reload_and_device_switch_change_neither_run_state_nor_version(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC03');
        $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"once"}');
        $before = $this->snapshot($run->id);

        $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]))->assertOk();
        $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]))->assertOk();
        $mobile = $this->actingAs($operator)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148'])
            ->get(route('projects.runs.show', [$project, $run->id]));
        $mobile->assertOk();
        $mobile->assertSee('data-run-version="'.$before['version'].'"', false);

        self::assertSame($before, $this->snapshot($run->id), 'Reload and device switch leave the run untouched.');
        self::assertSame(0, DB::table('jobs')->count());
    }

    /** TC-04 */
    public function test_ten_polls_carry_the_cursor_and_repeat_no_action_and_the_plain_view_matches(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC04');
        $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"poll"}');
        $before = $this->snapshot($run->id);

        $this->actingAs($operator);
        $component = Livewire::test(RunTimelinePage::class, ['project' => $project, 'runId' => $run->id]);
        $component->assertSet('seenVersion', $before['version']);
        $component->assertSet('eventCursor', $before['event_cursor']);
        $firstHtml = $this->componentBody($component->html());
        for ($i = 0; $i < 10; $i++) {
            $component->call('poll');
            self::assertSame($before, $this->snapshot($run->id), 'Poll '.($i + 1).' must not mutate anything.');
        }
        // Nothing changed, so every poll skipped the re-render and kept the cursor.
        $component->assertSet('seenVersion', $before['version']);
        $component->assertSet('eventCursor', $before['event_cursor']);
        self::assertSame($firstHtml, $this->componentBody($component->html()));

        // A new server-side event advances the cursor on the next poll — read-only.
        $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'Turn läuft.', 'ai6-031-poll-event');
        $component->call('poll');
        $component->assertSet('eventCursor', (int) RunEvent::query()->where('run_id', $run->id)->max('id'));
        $component->assertSee('Turn läuft.');
        self::assertSame($before['version'], (int) DB::table('runs')->where('id', $run->id)->value('version'));
        self::assertSame(0, DB::table('jobs')->count());

        // Without client-side updating the plain request shows the same view.
        $plain = (string) $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]))->getContent();
        foreach (['data-run-state="running"', 'data-artifact-kind="provider_raw"', 'Turn läuft.', 'data-manual-refresh', '<noscript>'] as $needle) {
            self::assertStringContainsString($needle, $plain);
        }
        self::assertStringContainsString('data-run-version="'.$before['version'].'"', $plain);
    }

    /** TC-05 */
    public function test_large_diff_log_and_artifact_list_are_limited_server_side_and_the_limit_is_named(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC05');
        $changed = [];
        for ($i = 1; $i <= 60; $i++) {
            $changed[] = ['path' => sprintf('app/File%02d.php', $i), 'change' => 'modified', 'bytes' => $i];
        }
        $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, json_encode(['changed_files' => $changed, 'decisions' => []], JSON_THROW_ON_ERROR));
        $diffLines = "diff --git a/app/Big.php b/app/Big.php\n--- a/app/Big.php\n+++ b/app/Big.php\n@@ -1 +1,3000 @@\n";
        for ($i = 1; $i <= 3000; $i++) {
            $diffLines .= sprintf("+Zeile %04d %s\n", $i, str_repeat('z', 40));
        }
        $this->storeObservedCheckpointDiff($run, $diffLines);
        for ($i = 1; $i <= 24; $i++) {
            $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"artefakt-'.$i.'"}');
        }
        $checkOutput = 'Prüfzeile '.str_repeat('x', 20000);
        $this->seedObservedCheckResult($run, $checkOutput);
        $logLine = 'Logzeile '.str_repeat('y', 20000);
        $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $logLine, 'ai6-031-long-event');

        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        $response->assertSee('data-pagination="changed-files"', false);
        $response->assertSee('Begrenzt: Dateien 1 bis 50 von 60 angezeigt (Seite 1 von 2, serverseitig 50 je Seite)');
        $response->assertSee('data-changed-path="app/File50.php"', false);
        $response->assertDontSee('data-changed-path="app/File51.php"', false);
        $response->assertSee('data-pagination="artifacts"', false);
        $response->assertSee('Begrenzt: Artefakte 1 bis 20 von 26 angezeigt (Seite 1 von 2, serverseitig 20 je Seite)');
        $response->assertSee('data-diff-text', false);
        $response->assertSee('+Zeile 0001');
        $response->assertDontSee('+Zeile 3000');
        $response->assertSee('data-truncated="diff"', false);
        $response->assertSee('Begrenzt: '.RunTimelinePage::DIFF_EXCERPT_BYTES.' von '.strlen($diffLines).' Bytes des Diffs angezeigt');
        $response->assertSee('data-truncated="check-output"', false);
        $response->assertSee('Begrenzt: '.RunTimelinePage::LOG_EXCERPT_BYTES.' von '.strlen($checkOutput).' Bytes der Checkausgabe angezeigt');
        $response->assertSee('data-truncated="event-payload"', false);
        $response->assertSee('[Begrenzt: '.RunTimelinePage::LOG_EXCERPT_BYTES.' von '.strlen($logLine).' Bytes angezeigt]');
        $body = (string) $response->getContent();
        self::assertLessThan(20000, substr_count($body, 'y'), 'The long log is not delivered in full.');

        $second = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]).'?changedFilesPage=2&artifactsPage=2');
        $second->assertOk();
        $second->assertSee('Begrenzt: Dateien 51 bis 60 von 60 angezeigt (Seite 2 von 2, serverseitig 50 je Seite)');
        $second->assertSee('data-changed-path="app/File51.php"', false);
        $second->assertDontSee('data-changed-path="app/File50.php"', false);
        $second->assertSee('Begrenzt: Artefakte 21 bis 26 von 26 angezeigt (Seite 2 von 2, serverseitig 20 je Seite)');
        self::assertSame(0, DB::table('jobs')->count());
    }

    /** TC-08 */
    public function test_the_timeline_shows_remaining_retention_deleted_raw_data_and_tombstone_origin_from_the_central_redaction(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC08');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"password=hunter2"}');
        $this->seedObservedCheckResult($run, 'token=abc123def456 in der Ausgabe');
        $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'Sitzung mit password=hunter2 gestartet.', 'ai6-031-retention-event');

        $before = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $before->assertOk();
        $before->assertSee('data-artifact-remaining-days="14"', false);
        $before->assertSee('data-retention-category="run_logs"', false);
        $before->assertSee('data-retention-remaining="90"', false);
        $before->assertSee('data-retention-category="check_logs"', false);
        $before->assertSee('data-retention-remaining="30"', false);
        $before->assertSee('Sitzung mit password='.RedactionMatchType::SECRET->marker().' gestartet.');
        $before->assertSee('token='.RedactionMatchType::TOKEN->marker().' in der Ausgabe');
        $before->assertDontSee('hunter2');
        $before->assertDontSee('abc123def456');

        // The run is terminal, so nothing defers; after the longest retention every raw datum is gone.
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-12-15 12:00:00');
        $this->app->make(RunRetentionSweep::class)->sweep();

        $after = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $after->assertOk();
        $after->assertSee('data-artifact="'.$artifact->id.'" data-artifact-kind="provider_raw"', false);
        $after->assertSee('data-artifact-retention="deleted"', false);
        $after->assertSee('data-artifact-tombstone', false);
        $after->assertSee('Tombstone-Herkunft: Kategorie agent_raw_output, Fingerprint-Key <code>app-key-v1</code> Version 1', false);
        $after->assertSee('data-retention-deleted="run_logs"', false);
        $after->assertSee('Tombstone-Herkunft: run_logs');
        $after->assertSee('data-retention-deleted="check_logs"', false);
        $after->assertSee('Tombstone-Herkunft: check_logs');
        $after->assertDontSee('data-check-output', false);
        $after->assertDontSee('Sitzung mit password=');
        $after->assertDontSee(RunRetentionSweep::RUN_LOG_TOMBSTONE);
        $after->assertDontSee(RunRetentionSweep::CHECK_LOG_TOMBSTONE);
        $after->assertDontSee('hunter2');

        // Every shown value comes from the central redaction: the page owns no redaction of its own.
        $page = file_get_contents(app_path('AI6/Runs/RunTimelinePage.php'));
        self::assertIsString($page);
        self::assertStringNotContainsString('->redact(', $page);
        self::assertStringContainsString('SafeTextRenderer', $page);
    }

    /** TC-14 */
    public function test_a_disabled_security_control_stays_visible_on_every_run_view_and_cannot_be_dismissed(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC14');
        $states = [];
        foreach (SecurityMeasure::cases() as $measure) {
            $states[$measure->value] = $measure !== SecurityMeasure::REQUIRE_AGENT_SANDBOX;
        }
        $this->app->instance(SecurityPolicy::class, new SecurityPolicy(SecurityProfile::CUSTOM, $states, true));

        foreach (['', '?eventsPage=2', '?dispositionFilter=must_fix'] as $query) {
            $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]).$query);
            $response->assertOk();
            $response->assertSee('data-security-banner', false);
            $response->assertSee('data-security-profile="custom"', false);
            $response->assertSee('data-security-disabled-count="1"', false);
            $response->assertSee('data-disabled-measure="AI6_SECURITY_REQUIRE_AGENT_SANDBOX"', false);
            $response->assertSee('reduzierter Modus ausdrücklich bestätigt');
            $response->assertSee('Dieser Hinweis ist dauerhaft und lässt sich nicht ausblenden.');
            $banner = $this->bannerMarkup((string) $response->getContent());
            self::assertSame(0, preg_match('/<button|wire:click|hidden|<details|<dialog|onclick/i', $banner), 'The notice offers no control that removes it.');
            self::assertStringContainsString('role="status"', $banner);
        }

        // The Livewire refresh keeps the notice as well.
        $this->actingAs($operator);
        $component = Livewire::test(RunTimelinePage::class, ['project' => $project, 'runId' => $run->id]);
        $component->assertSee('data-disabled-measure="AI6_SECURITY_REQUIRE_AGENT_SANDBOX"', false);
        $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'Neu.', 'ai6-031-banner-event');
        $component->call('poll');
        $component->assertSee('data-disabled-measure="AI6_SECURITY_REQUIRE_AGENT_SANDBOX"', false);
        self::assertSame(0, preg_match('/<button|wire:click/i', $this->bannerMarkup($component->html())));
    }

    /** AC-06: the free-text coverage status is raw at persistence and crosses redaction and presentation exactly once. */
    public function test_the_coverage_status_crosses_the_central_redaction_and_the_presentation_step(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-COV');
        $this->seedObservedCoverage($run, "covered\x1b[31m password=hunter2\x1b[0m");

        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        $response->assertSee('data-coverage-criterion="AC-01"', false);
        $response->assertSee("covered\u{FFFD} password=".RedactionMatchType::SECRET->marker());
        $response->assertDontSee('hunter2');
        self::assertStringNotContainsString("\x1b", (string) $response->getContent());
    }

    /** AC-03: a change without a new event or run version — delivery status, retention tombstone — still reaches the next poll. */
    public function test_polling_re_renders_when_a_delivery_status_or_a_retention_tombstone_changes_without_an_event(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-POLL2');
        $this->seedObservedCheckResult($run, 'Prüfausgabe bleibt bis zum Ablauf sichtbar.');
        $step = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        $request = $this->app->make(HumanRequestService::class)->open($run->fresh(), $this->humanRequestProposal('Zustellfrage'), (string) Str::uuid(), $step->idempotency_key);
        $before = $this->snapshot($run->id);

        $this->actingAs($operator);
        $component = Livewire::test(RunTimelinePage::class, ['project' => $project, 'runId' => $run->id]);
        $component->assertSee('data-delivery-status="'.$request->fresh()?->delivery_status->value.'"', false);
        $component->assertSee('data-check-output', false);

        // The mail job's delivery outcome writes no event and bumps no version.
        $request->fresh()?->forceFill(['delivery_status' => 'failed', 'delivery_failure_key' => 'mail_transport_failed'])->save();
        self::assertSame($before['version'], $this->snapshot($run->id)['version']);
        self::assertSame($before['events'], $this->snapshot($run->id)['events']);
        $component->call('poll');
        $component->assertSee('data-delivery-status="failed"', false);
        $component->assertSee('mail_transport_failed');

        // The retention run tombstones in place: same ids, same version.
        Date::setTestNow('2026-12-15 12:00:00');
        self::assertSame(1, $this->app->make(RunRetentionSweep::class)->sweep()->checkLogsPurged);
        self::assertSame($before['version'], $this->snapshot($run->id)['version']);
        $component->call('poll');
        $component->assertSee('data-retention-deleted="check_logs"', false);
        $component->assertDontSee('data-check-output', false);
        $component->assertDontSee('Prüfausgabe bleibt');
        self::assertSame(0, DB::table('jobs')->count());
    }

    /** AC-07/AC-13: a log tombstone stays a tombstone after the retention time is raised — its provenance is the bound deletion, never a re-derived expiry or the marker content. */
    public function test_a_raised_retention_time_after_the_sweep_leaves_run_and_check_log_tombstones_deleted(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-RAISED');
        $this->seedObservedCheckResult($run, 'Ausgabe vor der Löschung');
        $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'Eintrag vor der Löschung', 'ai6-031-raised-event');
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-12-15 12:00:00');
        $swept = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(1, $swept->checkLogsPurged);
        self::assertGreaterThanOrEqual(1, $swept->runLogsPurged);

        config(['ai6.retention.run_logs.max_days' => 3650, 'ai6.retention.check_logs.max_days' => 3650]);
        $response = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));

        $response->assertOk();
        $response->assertSee('data-retention-deleted="run_logs"', false);
        $response->assertSee('data-retention-deleted="check_logs"', false);
        $response->assertSee('Tombstone-Herkunft: check_logs, Fingerprint-Key <code>app-key-v1</code> Version 1', false);
        $response->assertDontSee('data-check-output', false);
        $response->assertDontSee('Ausgabe vor der Löschung');
        $response->assertDontSee('Eintrag vor der Löschung');
        $response->assertDontSee(RunRetentionSweep::RUN_LOG_TOMBSTONE);
        $response->assertDontSee(RunRetentionSweep::CHECK_LOG_TOMBSTONE);
        $response->assertDontSee('data-retention-category="check_logs"', false);
        $body = (string) $response->getContent();
        self::assertSame(0, preg_match('/data-event-retention="stored"/', $body), 'Every event of the terminal run is a tombstone; none shows a remaining retention.');
        self::assertStringNotContainsString('data-retention-remaining=', $body);
    }

    /** AC-07/AC-13: an expiry that passes on the clock alone — no write, no scheduler run — re-renders on the next poll: content and download links vanish, remaining days update. */
    public function test_polling_re_renders_a_clock_only_retention_change_without_any_database_write(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-CLOCK');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"tickt"}');
        $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, json_encode([
            'changed_files' => [['path' => 'app/Uhr.php', 'change' => 'modified', 'bytes' => 3]],
            'decisions' => [],
        ], JSON_THROW_ON_ERROR));
        $this->seedObservedCheckResult($run, 'Ausgabe bis zum Ablauf.');
        $before = $this->snapshot($run->id);
        $rows = $this->retentionRows($run->id);

        $this->actingAs($operator);
        $component = Livewire::test(RunTimelinePage::class, ['project' => $project, 'runId' => $run->id]);
        $component->assertSee('data-artifact-download="'.$artifact->id.'"', false);
        $component->assertSee('data-artifact-remaining-days="14"', false);
        $component->assertSee('data-retention-remaining="30"', false);
        $component->assertSee('data-check-output', false);
        $component->assertSee('data-changed-path="app/Uhr.php"', false);
        $unchanged = $this->componentBody($component->html());
        $component->call('poll');
        self::assertSame($unchanged, $this->componentBody($component->html()), 'Nothing changed: the poll skips the render.');

        // One day passes: the remaining days move without any write.
        Date::setTestNow('2026-09-03 12:00:00');
        $component->call('poll');
        $component->assertSee('data-artifact-remaining-days="13"', false);
        $component->assertSee('data-retention-remaining="29"', false);

        // Day 15: the provider output expired, the retention run has not run.
        Date::setTestNow('2026-09-17 12:00:00');
        $component->call('poll');
        $component->assertDontSee('data-artifact-download="'.$artifact->id.'"', false);
        $component->assertSee('data-artifact-download-refused="'.$artifact->id.'"', false);
        $component->assertSee('data-artifact-retention="expired"', false);

        // Day 31: check log and implementation summary expired as well.
        Date::setTestNow('2026-10-03 12:00:00');
        $component->call('poll');
        $component->assertDontSee('data-check-output', false);
        $component->assertDontSee('Ausgabe bis zum Ablauf.');
        $component->assertSee('data-retention-expired="check_logs"', false);
        $component->assertSee('data-summary-unavailable="expired"', false);
        $component->assertDontSee('data-changed-path="app/Uhr.php"', false);
        $component->assertDontSee('data-retention-deleted', false);

        self::assertSame($before, $this->snapshot($run->id), 'Polling writes nothing.');
        self::assertSame($rows, $this->retentionRows($run->id), 'No retention state was persisted; the clock alone changed the view.');
        self::assertSame(0, DB::table('jobs')->count());
    }

    /** AC-04: a live run is watched at its end — the newest timeline page is the default, older pages stay reachable. */
    public function test_the_timeline_shows_the_newest_page_by_default_and_older_pages_on_request(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-NEWEST');
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $fixtureEvents = RunEvent::query()->where('run_id', $run->id)->count();
        for ($i = 1; $i <= 110; $i++) {
            $orchestrator->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, sprintf('Ereignis %03d', $i), 'ai6-031-newest-'.$i);
        }
        $total = RunEvent::query()->where('run_id', $run->id)->count();

        $newest = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $newest->assertOk();
        $newest->assertSee('Ereignis 110');
        $newest->assertDontSee('Ereignis 001');
        $newest->assertSee('data-pagination="events"', false);
        $newest->assertSee('(Seite 2 von 2, serverseitig '.RunTimelinePage::EVENTS_PAGE_SIZE.' je Seite; die neueste Seite ist die Standardansicht)');
        $newest->assertSee('Ältere Ereignisse');
        self::assertSame($fixtureEvents + 110, $total);
        self::assertGreaterThan(RunTimelinePage::EVENTS_PAGE_SIZE, $total);

        $oldest = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]).'?eventsPage=1');
        $oldest->assertOk();
        $oldest->assertSee('Ereignis 001');
        $oldest->assertDontSee('Ereignis 110');
        $oldest->assertSee('Neuere Ereignisse');
    }

    /** AC-03: the finding filters are a plain GET form, usable without client-side updating. */
    public function test_the_finding_filters_work_as_a_plain_get_form(): void
    {
        [$run, $project, $operator] = $this->observedRun('AI6-031-FILTER');

        $page = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $page->assertOk();
        $page->assertSee('<form class="ai6-filters" method="get" action="'.route('projects.runs.show', [$project, $run->id]).'" data-finding-filters>', false);
        $page->assertSee('name="reviewerFilter"', false);
        $page->assertSee('name="dispositionFilter"', false);
        $page->assertSee('Filter anwenden');

        $filtered = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]).'?dispositionFilter=must_fix');
        $filtered->assertOk();
        $filtered->assertSee('value="must_fix" selected', false);
        $filtered->assertSee('Keine Findings für diesen Filter.');
        self::assertSame(0, DB::table('jobs')->count());
    }

    /** AC-13: expiry locks the view before the retention run removes anything; a printed marker is content, not provenance. */
    public function test_expiry_locks_the_view_before_the_retention_run_and_a_printed_marker_is_no_tombstone(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-EXPIRY');
        $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, json_encode([
            'changed_files' => [['path' => 'app/Abgelaufen.php', 'change' => 'modified', 'bytes' => 1]],
            'decisions' => [],
        ], JSON_THROW_ON_ERROR));
        $this->seedObservedCheckResult($run, 'Echte Ausgabe', 'php-targeted');
        $this->seedObservedCheckResult($run, RunRetentionSweep::CHECK_LOG_TOMBSTONE, 'php-all');

        // Before expiry: the real output is shown; the printed marker is shown as text with its own retention.
        $fresh = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $fresh->assertOk();
        $fresh->assertSee('data-changed-path="app/Abgelaufen.php"', false);
        $fresh->assertSee('Echte Ausgabe');
        $fresh->assertSee(RunRetentionSweep::CHECK_LOG_TOMBSTONE);
        $fresh->assertDontSee('data-retention-deleted', false);
        self::assertSame(2, substr_count((string) $fresh->getContent(), 'data-check-output'));

        // Day 31: artifacts and check logs expired, the run still active, nothing purged yet.
        Date::setTestNow('2026-10-03 12:00:00');
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->where('retention_state', 'deleted')->count());
        $expired = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $expired->assertOk();
        $expired->assertSee('data-summary-unavailable="expired"', false);
        $expired->assertDontSee('data-changed-path="app/Abgelaufen.php"', false);
        $expired->assertSee('data-artifact-retention="expired"', false);
        $expired->assertSee('data-retention-expired="check_logs"', false);
        $expired->assertDontSee('Echte Ausgabe');
        $expired->assertDontSee('data-check-output', false);
        $expired->assertDontSee('data-retention-deleted', false);
        $expired->assertDontSee('data-artifact-download=', false);
    }

    /** @return array{version: int, state: string, phase: string, events: int, event_cursor: int, artifacts: int, jobs: int, interventions: int} */
    private function snapshot(string $runId): array
    {
        $run = DB::table('runs')->where('id', $runId)->first();
        self::assertIsObject($run);

        return [
            'version' => (int) $run->version,
            'state' => (string) $run->state,
            'phase' => (string) $run->phase,
            'events' => RunEvent::query()->where('run_id', $runId)->count(),
            'event_cursor' => (int) RunEvent::query()->where('run_id', $runId)->max('id'),
            'artifacts' => RunArtifact::query()->where('run_id', $runId)->count(),
            'jobs' => ExecutionJob::query()->where('run_id', $runId)->count(),
            'interventions' => DB::table('interventions')->count(),
        ];
    }

    /** The diff artifact exactly as the worker binds it to the run's current checkpoint. */
    private function storeObservedCheckpointDiff(Run $run, string $text): RunArtifact
    {
        return $this->storeObservedArtifact($run, RunArtifactKind::CHECKPOINT_DIFF, $text, [
            'checkpoint_commit_sha' => $run->checkpoint_commit_sha,
            'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash,
            'from_oid' => $run->initial_run_base_sha,
            'to_oid' => $run->checkpoint_commit_sha,
            'total_bytes' => strlen($text),
            'truncated' => false,
            'unavailable' => null,
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function retentionRows(string $runId): array
    {
        $rows = [];
        foreach (['run_artifacts', 'run_events', 'check_results'] as $table) {
            $rows[$table] = DB::table($table)->where('run_id', $runId)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    /** The rendered component without the Livewire root attributes, which differ per request. */
    private function componentBody(string $html): string
    {
        $body = preg_replace('/\A\s*<div[^>]*>/', '<div>', $html);
        self::assertIsString($body);

        return $body;
    }

    private function bannerMarkup(string $html): string
    {
        $start = strpos($html, 'data-security-banner');
        self::assertIsInt($start);
        $end = strpos($html, '</section>', $start);
        self::assertIsInt($end);

        return substr($html, $start, $end - $start);
    }
}
