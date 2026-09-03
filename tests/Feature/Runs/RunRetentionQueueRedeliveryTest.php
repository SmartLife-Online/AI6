<?php

namespace Tests\Feature\Runs;

use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRetentionState;
use App\AI6\Runs\RunImplementation;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunRetentionSweep;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\After;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-13 of AI6-031, queue path: a queue job redelivered after the retention
 * run — the state a crashed worker leaves behind — cannot bring the removed
 * provider output back through the primary artifact store.
 */
final class RunRetentionQueueRedeliveryTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;

    #[After]
    public function resetClock(): void
    {
        Date::setTestNow();
    }

    public function test_a_redelivered_implementation_job_does_not_resurrect_removed_provider_output(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        $prepared = $this->preparedImplementationRun('AI6-031-QRD');
        $job = $this->executeImplement($prepared['run']);
        self::assertSame(ExecutionJobState::SUCCEEDED, $job->state, (string) $job->failure_code);
        $raw = RunArtifact::query()->where('run_id', $prepared['run']->id)
            ->where('kind', RunArtifactKind::PROVIDER_RAW->value)->firstOrFail();
        $root = $prepared['isolatedRoot'].'/../artifacts';
        $path = realpath($root).DIRECTORY_SEPARATOR.$prepared['run']->id.DIRECTORY_SEPARATOR.basename((string) $raw->storage_reference);
        self::assertFileExists($path);
        $removedBytes = (string) file_get_contents($path);
        self::assertNotSame('', $removedBytes);

        // The provider output expires after 14 days; the still running run may
        // defer by the 7-day grace, so day 22 removes it.
        Date::setTestNow('2026-09-24 12:00:00');
        self::assertGreaterThanOrEqual(1, $this->app->make(RunRetentionSweep::class)->sweep()->artifactsPurged);
        self::assertFileDoesNotExist($path);
        self::assertSame(RunArtifactRetentionState::DELETED, RunArtifact::query()->findOrFail($raw->id)->retention_state);

        // Redeliver the same step as after a worker crash: planned again, no
        // lease, with a run start recent enough that no runtime limit parks it
        // first, and the worktree file back at its pre-turn content so the
        // fake agent produces the very same change and the very same output.
        self::assertNotFalse(file_put_contents($prepared['worktree'].'/app/Example.php', "<?php\n\n// original\n"));
        DB::table('runs')->where('id', $prepared['run']->id)->update([
            'created_at' => Date::now()->subMinute(),
            'version' => DB::raw('version + 1'),
            'updated_at' => Date::now(),
        ]);
        ExecutionJob::query()->whereKey($job->id)->update([
            'state' => ExecutionJobState::PLANNED->value, 'lease_owner' => null, 'lease_expires_at' => null, 'attempts' => 0,
        ]);
        DB::table('jobs')->delete();

        (new ExecuteRunStep($job->id))->handle($this->app->make(RunOrchestrator::class), $this->app->make(RunImplementation::class));

        $redelivered = ExecutionJob::query()->findOrFail($job->id);
        self::assertSame(ExecutionJobState::FAILED, $redelivered->state, 'The redelivered turn ends as a named failure, not as a second publication.');
        self::assertSame('artifact_retention_expired', $redelivered->failure_code);
        self::assertSame(RunArtifactRetentionState::DELETED, RunArtifact::query()->findOrFail($raw->id)->retention_state);
        self::assertSame(0, RunArtifact::query()->where('run_id', $prepared['run']->id)
            ->where('kind', RunArtifactKind::PROVIDER_RAW->value)
            ->where('retention_state', RunArtifactRetentionState::STORED->value)->count());
        self::assertFileDoesNotExist($path);
        foreach (glob(realpath($root).DIRECTORY_SEPARATOR.$prepared['run']->id.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            self::assertStringNotContainsString($removedBytes, (string) file_get_contents($file), 'The removed bytes never reappear under the trusted root.');
        }
    }
}
