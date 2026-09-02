<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class QueueSessionIsolationTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @return list<InstructionCandidate> */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_sequential_queue_claims_materialize_new_implementation_and_review_sessions(): void
    {
        $firstApproval = $this->completedApproval('QUEUE-SESSION-1');
        $firstRun = $this->finalizedRun($firstApproval);
        $orchestrator = $this->app->make(RunOrchestrator::class);
        $firstImplementation = $orchestrator->ensureImplementationSlot($firstRun);
        $firstImplementation = $orchestrator->bindImplementationSession(
            $firstRun,
            $firstImplementation->slot_id,
            (string) Str::uuid(),
        );
        $firstReview = $orchestrator->materializeReviewSlots($firstRun)[0];
        $firstReview = $orchestrator->bindReviewSession($firstRun, $firstReview->slot_id, (string) Str::uuid());

        self::assertSame(1, Run::query()->whereKey($firstRun->id)->update([
            'state' => RunState::CANCELLED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        self::assertSame(1, Project::query()->whereKey($firstRun->project_id)
            ->where('active_run_id', $firstRun->id)
            ->update(['active_run_id' => null]));

        $secondApproval = $this->completedApproval(
            'QUEUE-SESSION-2',
            $firstApproval['project']->refresh(),
            $firstApproval['operator'],
        );
        $secondRun = $this->finalizedRun($secondApproval);
        $secondImplementation = $orchestrator->ensureImplementationSlot($secondRun);
        $secondImplementation = $orchestrator->bindImplementationSession(
            $secondRun,
            $secondImplementation->slot_id,
            (string) Str::uuid(),
        );
        $secondReview = $orchestrator->materializeReviewSlots($secondRun)[0];
        $secondReview = $orchestrator->bindReviewSession($secondRun, $secondReview->slot_id, (string) Str::uuid());

        self::assertNotSame($firstRun->id, $secondRun->id);
        self::assertNotSame($firstImplementation->slot_id, $secondImplementation->slot_id);
        self::assertNotSame($firstImplementation->session_id, $secondImplementation->session_id);
        self::assertNotSame($firstReview->slot_id, $secondReview->slot_id);
        self::assertNotSame($firstReview->session_id, $secondReview->session_id);
    }
}
