<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Auth\Models\User;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunTimelinePage;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\BrowserSmokeTestHarness;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-02 of AI6-031: real 375x812 smoke over the run page filled with real
 * findings from a review round, a diff with an overlong path, an overlong
 * check output, artifacts and an open human request, plus the human-request
 * detail — over their registered routes, behind the existing flag. Without
 * the flag it skips and is never reported as executed.
 */
final class RunObservationMobileBrowserSmokeTest extends TicketUiTestCase
{
    use BrowserSmokeTestHarness;
    use BuildsFixLoopFixture;
    use BuildsReviewRoundFixture;

    private const LONG_PATH = 'app/AI6/SehrLangerModulname/NochLaengererUnterordner/EineKlasseMitAussergewoehnlichLangemNamenFuerDieMobileDarstellung.php';

    private const AREAS = ['[data-run-overview]', '[data-diff]', '[data-diff-text]', '[data-checks]', '[data-findings-list]', '[data-human-requests]', '[data-security-banner]', '[data-artifacts]', '[data-timeline]'];

    public function test_run_overview_diff_findings_and_human_request_detail_do_not_scroll_horizontally_on_a_phone(): void
    {
        $chromedriverBinary = $this->requireBrowserSmokeChromedriver('The run observation mobile browser smoke test');
        $password = bin2hex(random_bytes(16));
        [$user, $secret, $project, $run, $request, $finding] = $this->seedFileDatabase($password);
        $appPort = $this->freePort();
        $driverPort = $this->freePort();
        $baseUrl = 'http://ai6-smoke.test:'.$appPort;

        try {
            // The artifact bytes live under the temporary root the seeding
            // configured; the separate server process needs the same root.
            $this->startApplicationServer($appPort, ['AI6_RUN_ARTIFACT_ROOT' => (string) config('ai6.run_artifacts.root')]);
            $this->startChromedriver($chromedriverBinary, $driverPort);
            $this->createBrowserSession();
            $this->setViewport(375, 812, true);

            $this->navigate($baseUrl.'/login');
            $this->type('#email', (string) $user->email);
            $this->type('input[type=password]', $password."\u{E007}");
            $this->waitForUrlContaining('/auth/factor');
            $this->type('input[inputmode=numeric]', $this->currentTotpCode($secret)."\u{E007}");
            $this->waitForUrlContaining('/projects');

            $this->navigate($baseUrl.'/projects/'.$project->getKey().'/runs/'.$run->id);
            // The filled areas, not only their markers: a real finding, the
            // overlong diff path, the overlong check output with its named
            // limit, the artifact download and the open human request.
            $this->waitForSourceContaining('data-run-overview');
            $this->waitForSourceContaining('data-finding-id="'.$finding->id.'"');
            $this->waitForSourceContaining('data-changed-path="'.self::LONG_PATH.'"');
            $this->waitForSourceContaining('data-diff-text');
            $this->waitForSourceContaining('+Zeile0001');
            $this->waitForSourceContaining('data-truncated="diff"');
            $this->waitForSourceContaining('data-check-output');
            $this->waitForSourceContaining('data-truncated="check-output"');
            $this->waitForSourceContaining('data-human-request="'.$request->id.'"');
            $this->waitForSourceContaining('data-artifact-download=');
            $this->waitForSourceContaining('data-security-banner');
            $this->waitForSourceContaining('data-timeline');
            $this->assertNoHorizontalScrolling('Runübersicht mit Findings, Diff, Checks und Human Request', 375, 812);
            $this->assertNoAreaOverflows('Runübersicht');
            self::assertSame(0, (int) $this->execute('return document.querySelectorAll("style,[style]").length;'), 'The run page inserts no inline style.');
            // The 2-second wire:poll fires at least once in this window; a
            // synchronous WebDriver script cannot await it, so the test waits.
            usleep(2_500_000);
            $this->waitForSourceContaining('data-finding-id="'.$finding->id.'"');
            $this->waitForSourceContaining('data-truncated="diff"');
            self::assertStringNotContainsString('+Zeile2000', $this->pageSource(), 'The overlong diff stays limited after polling.');
            $this->assertNoHorizontalScrolling('Runübersicht nach Polling', 375, 812);
            $this->assertNoAreaOverflows('Runübersicht nach Polling');
            $this->assertConsoleFreeOfPolicyViolations();

            $this->navigate($baseUrl.'/projects/'.$project->getKey().'/human-requests/'.$request->id);
            $this->waitForSourceContaining('name="chosen_effect"');
            $this->assertNoHorizontalScrolling('Human-Request-Detail', 375, 812);
            self::assertSame(0, (int) $this->execute('return document.querySelectorAll("style,[style]").length;'));
            $this->assertConsoleFreeOfPolicyViolations();
        } finally {
            $this->tearDownBrowserSmokeHarness();
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownBrowserSmokeHarness();

        parent::tearDown();
    }

    private function assertNoAreaOverflows(string $viewName): void
    {
        foreach (self::AREAS as $selector) {
            $overflow = $this->execute(
                'var area = document.querySelector('.json_encode($selector, JSON_THROW_ON_ERROR).');'
                .' return area === null ? null : area.scrollWidth - area.clientWidth;',
            );
            self::assertIsNumeric($overflow, $viewName.': '.$selector.' is rendered.');
            self::assertLessThanOrEqual(0, (int) $overflow, $viewName.': '.$selector.' must not require horizontal scrolling.');
        }
    }

    /** @return array{User, string, Project, Run, HumanRequest, Finding} */
    private function seedFileDatabase(string $password): array
    {
        $this->initializeBrowserSmokeDatabase('ai6-031-run-browser-smoke');
        Mail::fake();
        $prepared = $this->preparedReviewRun('AI6-031-MOBILE');
        $run = $prepared['run'];
        $this->noChangeFixAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::SUCCESS,
        ]);
        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        /** @var Finding $finding */
        $finding = Finding::query()->where('run_id', $run->id)->orderBy('created_at')->firstOrFail();
        $run = $run->fresh() ?? $run;

        $context = new RedactionContext((string) $run->project_id, $run->id, 'run-observation-smoke');
        $store = $this->app->make(RunArtifactStore::class);
        $store->store($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, json_encode([
            'changed_files' => [['path' => self::LONG_PATH, 'change' => 'modified', 'bytes' => 4096]],
            'decisions' => [['key' => 'd1', 'title' => 'Entscheidung', 'rationale' => str_repeat('BegruendungstextOhneUmbruchmoeglichkeit', 12)]],
        ], JSON_THROW_ON_ERROR), ['kind' => RunArtifactKind::IMPLEMENTATION_SUMMARY->value], $context);
        $store->store($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"'.str_repeat('x', 3000).'"}', ['kind' => RunArtifactKind::PROVIDER_RAW->value], $context);
        // A bound, non-empty, overlong patch text: longer than the shown
        // excerpt, with lines that do not break on their own.
        $patch = "diff --git a/app/Lang.php b/app/Lang.php\n--- a/app/Lang.php\n+++ b/app/Lang.php\n@@ -1 +1,2000 @@\n";
        for ($i = 1; $i <= 2000; $i++) {
            $patch .= sprintf("+Zeile%04d%s\n", $i, str_repeat('LangeDiffzeileOhneLeerzeichen', 3));
        }
        self::assertGreaterThan(RunTimelinePage::DIFF_EXCERPT_BYTES, strlen($patch));
        $store->store($run, RunArtifactKind::CHECKPOINT_DIFF, $patch, [
            'kind' => RunArtifactKind::CHECKPOINT_DIFF->value,
            'checkpoint_commit_sha' => $run->checkpoint_commit_sha, 'checkpoint_tree_sha' => $run->checkpoint_tree_sha,
            'diff_hash' => $run->checkpoint_diff_hash, 'from_oid' => $run->initial_run_base_sha, 'to_oid' => $run->checkpoint_commit_sha,
            'total_bytes' => strlen($patch), 'truncated' => false, 'unavailable' => null,
        ], $context);

        $tree = (string) $run->checkpoint_tree_sha;
        $output = 'Prüfausgabe '.str_repeat('LangeAusgabezeileOhneLeerzeichen', 400)."\n\x1b[31mrot\x1b[0m";
        CheckResultRecord::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'phase' => CheckPhase::BEFORE_REVIEW,
            'evidence_epoch' => $run->evidence_epoch, 'profile' => 'php-targeted',
            'state' => CheckResultState::FAILED, 'reason' => 'check_failed', 'exit_code' => 1,
            'duration_ms' => 12,
            'redacted_output' => $this->app->make(Redactor::class)->redact($output, new RedactionContext((string) $run->project_id, $run->id, 'check-output-smoke'))->text,
            'tree_sha' => $tree, 'result_tree_sha' => $tree,
            'declared_side_effects' => false, 'declared_network' => false, 'declared_mutates' => false,
            'result_key' => CheckResult::key($run->id, $run->evidence_epoch, CheckPhase::BEFORE_REVIEW, 'php-targeted', $tree),
        ]);

        $fixStep = $this->stepJob($run, ExecutionStepType::FIX, 1);
        $request = $this->app->make(HumanRequestService::class)->open(
            $run->fresh() ?? $run,
            new HumanRequestProposal(
                'clarification',
                'Rückfrage zur Umsetzung mit einem sehr langen Titel für die mobile Darstellung',
                str_repeat('NachrichtOhneUmbruchmoeglichkeit', 10),
                'Die gebundene Umsetzung benötigt eine Auswahl.',
                'select',
                [new HumanRequestOption('a', 'Option A'), new HumanRequestOption('b', 'Option B')],
                'a',
                [self::LONG_PATH],
                [],
            ),
            (string) Str::uuid(),
            $fixStep->idempotency_key,
        );

        $user = ProjectMembership::query()->where('project_id', $run->project_id)
            ->where('role', ProjectRole::OPERATOR->value)->firstOrFail()->user()->firstOrFail();
        $user->forceFill([
            'email' => 'run-smoke@example.test',
            'password' => $password,
        ])->save();
        $secret = $this->createConfirmedTotp($user);

        return [$user->fresh() ?? $user, $secret, $run->project()->firstOrFail(), $run->fresh() ?? $run, $request->fresh() ?? $request, $finding];
    }
}
