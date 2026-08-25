<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\HumanLoop\HumanRequestService;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Runs\ExecutionJobState;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ReviewResumeAndFailureTest extends TicketUiTestCase
{
    use BuildsReviewRoundFixture;

    /** TC-09/TC-10: resume is slot-local and the later invalid slot remains visible. */
    public function test_resume_continues_one_slot_then_records_the_later_schema_failure(): void
    {
        Mail::fake();
        $prepared = $this->preparedReviewRun('AI6-023-RUN-MATRIX');
        $adapter = $this->reviewAdapter([
            $this->reviewSlotIds[0] => [AgentScenario::HUMAN_REQUEST, AgentScenario::SUCCESS],
            $this->reviewSlotIds[1] => AgentScenario::INVALID_JSON,
        ]);

        $waiting = $this->executeReview($prepared['run']);
        self::assertSame(ExecutionJobState::WAITING, $waiting->state);
        self::assertSame(1, $adapter->turnCount);
        $request = HumanRequest::query()->where('run_id', $prepared['run']->id)->sole();
        self::assertSame($this->reviewSlotIds[0], $request->bound_agent_slot);

        $this->app->make(HumanRequestService::class)->answer(
            $request,
            $request->attentionUser()->firstOrFail(),
            $request->bound_run_version,
            $request->bound_ticket_contract,
            $request->bound_checkpoint,
            $request->bound_scope,
            $request->bound_agent_slot,
            $request->bound_requested_effect,
            'a',
        );
        $failed = $this->executeReview($prepared['run']->fresh());

        self::assertSame(ExecutionJobState::WAITING, $failed->state);
        self::assertSame('invalid_json', $prepared['run']->fresh()->wait_reason?->value);
        self::assertSame('invalid_json', HumanRequest::query()->where('run_id', $prepared['run']->id)
            ->where('resolution_state', 'open')->sole()->kind);
        self::assertSame(
            [
                $this->reviewSlotIds[0], $this->reviewSlotIds[0],
                $this->reviewSlotIds[1], $this->reviewSlotIds[1], $this->reviewSlotIds[1],
            ],
            array_column($adapter->contextPackages, 'slot_id'),
        );
        self::assertSame([1, 2, 1, 2, 3], array_column($adapter->contextPackages, 'attempt'));
        self::assertSame(1, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $this->reviewSlotIds[0])
            ->where('invocation_outcome', ReviewInvocationOutcome::VALID_RESULT->value)->count());
        self::assertSame(3, ReviewResult::query()->where('run_id', $prepared['run']->id)
            ->where('slot_id', $this->reviewSlotIds[1])
            ->where('invocation_outcome', ReviewInvocationOutcome::INVALID_JSON->value)
            ->where('failure_code', 'invalid_json')->count());
    }
}
