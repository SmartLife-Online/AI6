<?php

namespace Tests\Feature\Reviews;

use App\AI6\Agents\AgentScenario;
use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectRole;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\Models\Run;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\BrowserSmokeTestHarness;
use Tests\Feature\Runs\BuildsFixLoopFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/** TC-11: real 375x812 proof for the run timeline's advisory evidence block. */
final class FindingVerificationMobileBrowserSmokeTest extends TicketUiTestCase
{
    use BrowserSmokeTestHarness;
    use BuildsFixLoopFixture;
    use BuildsReviewRoundFixture;

    public function test_verification_evidence_is_usable_without_horizontal_scrolling_on_a_phone(): void
    {
        $chromedriverBinary = $this->requireBrowserSmokeChromedriver(
            'The finding-verification mobile browser smoke test',
        );
        $password = bin2hex(random_bytes(16));
        [$user, $secret, $project, $run] = $this->seedFileDatabase($password);
        $appPort = $this->freePort();
        $driverPort = $this->freePort();
        $baseUrl = 'http://ai6-smoke.test:'.$appPort;

        try {
            $this->startApplicationServer($appPort);
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
            $this->waitForSourceContaining('Advisory Verifierevidenz');
            $this->waitForSourceContaining('Deterministische unabhängige Verifierevidenz.');
            $this->waitForSourceContaining('data-verification-evidence');
            $this->assertNoHorizontalScrolling('Run-Timeline mit Verifierevidenz', 375, 812);

            $evidenceOverflow = $this->execute(
                'var evidence = document.querySelector("[data-verification-evidence]");'
                .' return evidence === null ? null : evidence.scrollWidth - evidence.clientWidth;',
            );
            self::assertIsNumeric($evidenceOverflow);
            self::assertLessThanOrEqual(
                0,
                (int) $evidenceOverflow,
                'The verification-evidence block itself must not require horizontal scrolling.',
            );
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

    /** @return array{User, string, Project, Run} */
    private function seedFileDatabase(string $password): array
    {
        $this->initializeBrowserSmokeDatabase('ai6-finding-verification-browser-smoke');
        Mail::fake();
        $prepared = $this->preparedReviewRun('AI6-043-MOBILE-EVIDENCE');
        $run = $prepared['run'];
        $this->noChangeFixAdapter([
            $this->reviewSlotIds[0] => AgentScenario::FINDINGS,
            $this->reviewSlotIds[1] => AgentScenario::SUCCESS,
        ]);

        self::assertSame(ExecutionJobState::SUCCEEDED, $this->executeReviewRound($run, 1)->state);
        self::assertSame(
            ExecutionJobState::SUCCEEDED,
            $this->stepJob($run, ExecutionStepType::VERIFY, 1)->fresh()->state,
        );
        self::assertSame(1, ReviewResult::query()->where('run_id', $run->id)
            ->where('role', 'finding_verification')->where('invocation_outcome', 'valid_result')->count());

        $user = ProjectMembership::query()->where('project_id', $run->project_id)
            ->where('role', ProjectRole::OPERATOR->value)->firstOrFail()->user()->firstOrFail();
        $user->forceFill([
            'email' => 'finding-verification-smoke@example.test',
            'password' => $password,
        ])->save();
        $secret = $this->createConfirmedTotp($user);

        return [$user->fresh(), $secret, $run->project()->firstOrFail(), $run->fresh()];
    }
}
