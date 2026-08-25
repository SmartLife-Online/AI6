<?php

namespace Tests\Feature\HumanLoop;

use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\HumanLoop\Http\HumanRequestAnswerController;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\InterventionAuthorization;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunLimitPolicy;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Runs\BuildsHumanRequestFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ResourceLimitWaitTest extends TicketUiTestCase
{
    use BuildsHumanRequestFixture;

    /** TC-08 */
    public function test_resource_limit_opens_one_request_and_replays_are_idempotent(): void
    {
        Mail::fake();
        $opened = $this->openedResourceLimit('AI6-019-TC08');
        self::assertSame(WaitReason::RESOURCE_LIMIT, $opened['run']->fresh()->wait_reason);
        self::assertSame(1, HumanRequest::query()->where('run_id', $opened['run']->id)->count());

        try {
            $this->app->make(HumanRequestService::class)->open(
                $opened['run']->fresh(),
                $opened['proposal'],
                $opened['slot'],
                $opened['step']->idempotency_key,
                WaitReason::RESOURCE_LIMIT,
                $opened['limit'],
            );
            self::fail('A second open request must be rejected.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('open_request_exists', $rejected->reason);
        }
        self::assertSame(1, HumanRequest::query()->where('run_id', $opened['run']->id)->count());

        $this->app->make(HumanRequestService::class)->answer(
            $opened['request'],
            $opened['operator'],
            $opened['request']->bound_run_version,
            $opened['request']->bound_ticket_contract,
            $opened['request']->bound_checkpoint,
            $opened['request']->bound_scope,
            $opened['request']->bound_agent_slot,
            $opened['request']->bound_requested_effect,
            'reduce',
        );
        self::assertSame(RunState::RUNNING, $opened['run']->fresh()->state);
        self::assertNull($opened['run']->fresh()->wait_reason);
    }

    /** TC-08 */
    public function test_an_increase_above_the_server_maximum_is_rejected(): void
    {
        Mail::fake();
        $opened = $this->openedResourceLimit('AI6-019-TC08-MAX', observed: 20000001, maximum: 20);
        $this->expectException(HumanRequestRejected::class);
        $this->app->make(HumanRequestService::class)->answer(
            $opened['request'],
            $opened['operator'],
            $opened['request']->bound_run_version,
            $opened['request']->bound_ticket_contract,
            $opened['request']->bound_checkpoint,
            $opened['request']->bound_scope,
            $opened['request']->bound_agent_slot,
            $opened['request']->bound_requested_effect,
            'increase',
            $this->authorization($opened['operator'], $opened['request'], 'increase'),
        );
    }

    /** TC-08 */
    public function test_an_authorized_increase_raises_the_granted_limit(): void
    {
        Mail::fake();
        $opened = $this->openedResourceLimit('AI6-019-TC08-INC', observed: 41, maximum: 40);
        $this->app->make(HumanRequestService::class)->answer(
            $opened['request'],
            $opened['operator'],
            $opened['request']->bound_run_version,
            $opened['request']->bound_ticket_contract,
            $opened['request']->bound_checkpoint,
            $opened['request']->bound_scope,
            $opened['request']->bound_agent_slot,
            $opened['request']->bound_requested_effect,
            'increase',
            $this->authorization($opened['operator'], $opened['request'], 'increase'),
        );
        $effective = $this->app->make(RunLimitPolicy::class)->effective($opened['run']->fresh());
        self::assertSame(41, $effective['max_changed_files']);
    }

    /** TC-08 */
    public function test_legacy_cancel_cannot_resolve_resource_limit_outside_the_status_saga(): void
    {
        Mail::fake();
        $opened = $this->openedResourceLimit('AI6-019-TC08-CAN');
        try {
            $this->app->make(HumanRequestService::class)->answer(
                $opened['request'],
                $opened['operator'],
                $opened['request']->bound_run_version,
                $opened['request']->bound_ticket_contract,
                $opened['request']->bound_checkpoint,
                $opened['request']->bound_scope,
                $opened['request']->bound_agent_slot,
                $opened['request']->bound_requested_effect,
                HumanRequestService::CANCEL_EFFECT,
            );
            self::fail('The legacy local cancellation path was accepted.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('legacy_cancel_forbidden', $rejected->reason);
        }
        self::assertSame(RunState::WAITING, $opened['run']->fresh()->state);
    }

    /**
     * @return array{
     *     request: HumanRequest,
     *     run: Run,
     *     project: Project,
     *     operator: User,
     *     slot: string,
     *     proposal: HumanRequestProposal,
     *     step: ExecutionJob,
     *     limit: ImportLimitResult
     * }
     */
    private function openedResourceLimit(string $ticketId, int $observed = 21, int $maximum = 20): array
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
        $run = $this->finalizedRun($fixture);
        $run = $this->finishPreflight($this->bindRunWorkspace($fixture, $run));
        $proposal = new HumanRequestProposal(
            'resource_limit',
            'Limit',
            'Zu viele Dateien.',
            'Importgrenze.',
            'select',
            [new HumanRequestOption('reduce', 'Reduzieren'), new HumanRequestOption('increase', 'Erhöhen')],
            'reduce',
            [],
            [],
        );
        $step = ExecutionJob::query()->where('run_id', $run->id)
            ->where('step_type', ExecutionStepType::IMPLEMENT->value)->firstOrFail();
        $limit = new ImportLimitResult(ImportLimit::MAX_CHANGED_FILES, $observed, $maximum);
        $request = $this->app->make(HumanRequestService::class)->open(
            $run,
            $proposal,
            (string) $step->id,
            $step->idempotency_key,
            WaitReason::RESOURCE_LIMIT,
            $limit,
        );

        return [
            'request' => $request->fresh(),
            'run' => $run->refresh(),
            'project' => $fixture['project']->refresh(),
            'operator' => $fixture['operator'],
            'slot' => (string) $step->id,
            'proposal' => $proposal,
            'step' => $step,
            'limit' => $limit,
        ];
    }

    private function authorization(User $actor, HumanRequest $request, string $effect): InterventionAuthorization
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId('resource-limit-'.$actor->id.'-'.bin2hex(random_bytes(4)));
        $session->start();
        $proof = Request::create('/human-request', 'POST');
        $proof->setLaravelSession($session);
        $guard = $this->app->make(StepUpGuard::class);
        $guard->markSatisfied($proof, $actor, HumanRequestAnswerController::STEP_UP_ACTION);

        return InterventionAuthorization::consumeFresh(
            $proof,
            $actor,
            $guard,
            HumanRequestAnswerController::STEP_UP_ACTION,
            [$request->run_id, $request->id, $request->bound_run_version, $effect],
        );
    }
}
