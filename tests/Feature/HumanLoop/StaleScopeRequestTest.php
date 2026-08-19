<?php

namespace Tests\Feature\HumanLoop;

use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\HumanLoop\ScopeApprovalService;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectConfiguration;
use App\AI6\Projects\ProjectRole;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\ScopeDecision;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-07: a decision with a stale run version, stale scope binding or a
 * meanwhile amended ticket contract ends as a named rejection without effect.
 */
final class StaleScopeRequestTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** @return array{run: Run, request: HumanRequest} */
    private function openScopeRequest(string $ticketId): array
    {
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $attention = $this->createUser(['email' => 'attention-'.$ticketId.'@example.test']);
        $fixture = $this->completedApproval($ticketId, attentionUser: $attention);
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $this->finalizedRun($fixture)));
        $step = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        $configuration = $this->app->make(EffectiveProjectConfiguration::class)
            ->for($run->fresh()->project()->firstOrFail())->configuration;
        // The strict variant of the third track (plan §8.2): an unlisted path
        // needs a human decision, which is what this stale-answer proof needs.
        $values = $configuration->values;
        $values['scope']['unlisted_paths'] = 'require_approval';
        $request = $this->app->make(ScopeApprovalService::class)->requestOrAutoAllow(
            $run,
            new ProjectConfiguration($values),
            'docs/stale.md',
            false,
            (string) $step->id,
            $step->idempotency_key,
        );
        self::assertNotNull($request);

        return ['run' => $run->refresh(), 'request' => $request->refresh()];
    }

    /** @param array<string, int|string> $overrides */
    private function answer(HumanRequest $request, Run $run, array $overrides): void
    {
        $approver = $this->createUser();
        $this->addMembership($approver, $run->project()->firstOrFail(), ProjectRole::APPROVER);
        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $approver,
            $overrides['run_version'] ?? $request->bound_run_version,
            $overrides['ticket_contract'] ?? $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $overrides['scope'] ?? $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'approve',
        );
    }

    /**
     * The rejected answer left nothing behind: the run still waits and neither
     * the counter nor the decision table moved past the state that existed
     * before the answer (a confirmed amendment may legitimately have taken up
     * ticket-file paths beforehand).
     */
    private function assertNoEffect(Run $run, int $expectedCount = 0, int $expectedDecisions = 0): void
    {
        $fresh = $run->fresh();
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame($expectedCount, $fresh->added_scope_paths_count);
        self::assertSame($expectedDecisions, ScopeDecision::query()->where('run_id', $run->id)->count());
    }

    public function test_a_stale_run_version_is_rejected_without_effect(): void
    {
        Mail::fake();
        $opened = $this->openScopeRequest('AI6-020-STALE-VERSION');

        try {
            $this->answer($opened['request'], $opened['run'], ['run_version' => $opened['request']->bound_run_version + 1]);
            self::fail('A stale run version must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('stale_run_version', $rejected->reason);
        }
        $this->assertNoEffect($opened['run']);
    }

    public function test_a_stale_scope_binding_is_rejected_without_effect(): void
    {
        Mail::fake();
        $opened = $this->openScopeRequest('AI6-020-STALE-SCOPE');

        try {
            $this->answer($opened['request'], $opened['run'], ['scope' => str_repeat('9', 64)]);
            self::fail('A stale scope binding must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('stale_scope', $rejected->reason);
        }
        $this->assertNoEffect($opened['run']);
    }

    /**
     * TC-07, HUM-004: the real drift case — the run version moves after the
     * request was opened, and the answer arrives with exactly the bindings the
     * request stored.
     *
     * Comparing the caller's values against the stored binding alone cannot
     * catch this: both are the same stale numbers. Only the compare-and-swap
     * of the scope decision against the run refuses it.
     */
    public function test_a_run_that_moved_after_the_request_refuses_the_stored_binding(): void
    {
        Mail::fake();
        $opened = $this->openScopeRequest('AI6-020-STALE-DRIFT');
        $before = $opened['run']->fresh();

        // A confirmed amendment moves the run version while the request is open.
        $amended = $this->app->make(RunOrchestrator::class)->applyContractAmendment(
            $before,
            str_repeat('d', 64),
            str_repeat('1', 64),
            str_repeat('2', 64),
            (array) $before->scope_snapshot,
            str_repeat('3', 64),
            (array) $before->config_snapshot,
            (string) $before->config_hash,
            (array) $before->prompt_snapshot,
            (string) $before->prompt_hash,
            $this->app->make(CanonicalJson::class),
            12,
        );
        self::assertGreaterThan($opened['request']->bound_run_version, $amended->version);
        $countAfterAmendment = $amended->added_scope_paths_count;
        $decisionsAfterAmendment = ScopeDecision::query()->where('run_id', $opened['run']->id)->count();

        // The answer carries exactly the stored bindings — nothing the caller
        // could have noticed — and is still refused.
        try {
            $this->answer($opened['request'], $opened['run'], []);
            self::fail('An answer bound to a run version that moved must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('stale_run_version', $rejected->reason);
        }

        $this->assertNoEffect($opened['run'], $countAfterAmendment, $decisionsAfterAmendment);
        self::assertSame(0, ScopeDecision::query()
            ->where('run_id', $opened['run']->id)->where('path', 'docs/stale.md')->count());
        self::assertSame('open', $opened['request']->fresh()->resolution_state->value);
    }

    public function test_a_meanwhile_amended_ticket_contract_is_rejected_without_effect(): void
    {
        Mail::fake();
        $opened = $this->openScopeRequest('AI6-020-STALE-CONTRACT');

        // A confirmed contract amendment rebinds the run's effective contract;
        // the caller now hands the current contract in, which no longer matches
        // the request's stored binding.
        $amended = $this->app->make(RunOrchestrator::class)->applyContractAmendment(
            $opened['run'],
            str_repeat('d', 64),
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['ticket_files' => ['app/Example.php'], 'project_scope' => []],
            str_repeat('3', 64),
            (array) $opened['run']->config_snapshot,
            (string) $opened['run']->config_hash,
            (array) $opened['run']->prompt_snapshot,
            (string) $opened['run']->prompt_hash,
            $this->app->make(CanonicalJson::class),
            12,
        );
        self::assertSame(str_repeat('2', 64), $amended->ticket_contract_sha256);

        try {
            $this->answer($opened['request'], $opened['run'], ['ticket_contract' => (string) $amended->ticket_contract_sha256]);
            self::fail('An amended ticket contract must reject the stale request.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('stale_ticket_contract', $rejected->reason);
        }
        // The amendment itself took up its one ticket-file path; the rejected
        // answer added nothing on top of that.
        $this->assertNoEffect($opened['run'], expectedCount: 1, expectedDecisions: 1);
    }
}
