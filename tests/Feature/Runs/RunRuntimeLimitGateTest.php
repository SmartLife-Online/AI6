<?php

namespace Tests\Feature\Runs;

use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunState;
use App\AI6\Runs\WaitReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-01 for max_run_minutes: the runtime limit is measured from the persisted
 * run start and evaluated before the agent job — at the boundary the step
 * continues, one above it stops before the provider without partial effect.
 */
final class RunRuntimeLimitGateTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    public function test_the_runtime_limit_continues_at_the_boundary_and_parks_one_above(): void
    {
        Mail::fake();

        $prepared = $this->preparedImplementationRun('AI6-026-RUNTIME-AT', limitOverrides: ['max_run_minutes' => 60]);
        $this->ageRun($prepared['run'], 60);
        $job = $this->executeImplement($prepared['run']->fresh());
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        self::assertSame(RunState::RUNNING, $prepared['run']->fresh()->state);

        $prepared = $this->preparedImplementationRun('AI6-026-RUNTIME-OVER', limitOverrides: ['max_run_minutes' => 60]);
        $this->ageRun($prepared['run'], 61);
        $original = (string) file_get_contents($prepared['worktree'].'/app/Example.php');
        $job = $this->executeImplement($prepared['run']->fresh());

        self::assertSame(ExecutionJobState::WAITING, $job->state, (string) $job->failure_code);
        $fresh = $prepared['run']->fresh();
        self::assertSame(RunState::WAITING, $fresh->state);
        self::assertSame(WaitReason::RESOURCE_LIMIT, $fresh->wait_reason);
        // The stop happened before the provider call: no import, no artifact.
        self::assertSame($original, (string) file_get_contents($prepared['worktree'].'/app/Example.php'));
        self::assertSame(0, RunArtifact::query()->where('run_id', $fresh->id)
            ->whereIn('kind', ['implementation_summary', 'provider_raw'])->count());
        $pending = RunArtifact::query()->where('run_id', $fresh->id)
            ->where('kind', 'limit_pending')->firstOrFail();
        self::assertSame('max_run_minutes', $pending->redacted_metadata['limit']);
        self::assertSame(61, $pending->redacted_metadata['observed']);
        self::assertSame(60, $pending->redacted_metadata['maximum']);
        self::assertSame('resource_limit', HumanRequest::query()->where('run_id', $fresh->id)
            ->where('resolution_state', 'open')->sole()->kind);
    }

    private function ageRun(Run $run, int $minutes): void
    {
        DB::table('runs')->where('id', $run->id)->update([
            'created_at' => now()->subMinutes($minutes),
            'version' => DB::raw('version + 1'),
        ]);
    }
}
