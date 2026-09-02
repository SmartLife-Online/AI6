<?php

namespace Tests\Unit\Runs;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\ApprovalQueue;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\QueueReevaluation;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Runs\BuildsFinalizedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ApprovalQueueTest extends TicketUiTestCase
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

    public function test_versions_fifo_reevaluation_and_explicit_requeue_preserve_the_queue_contract(): void
    {
        try {
            Date::setTestNow('2026-09-02 10:00:00');
            $first = $this->completedApproval('QUEUE-FIFO-1');
            Date::setTestNow('2026-09-02 10:00:01');
            $second = $this->completedApproval('QUEUE-FIFO-2', $first['project'], $first['operator']);
            Date::setTestNow('2026-09-02 10:00:02');
            $third = $this->completedApproval('QUEUE-FIFO-3', $first['project'], $first['operator']);

            $queue = $this->app->make(ApprovalQueue::class);
            self::assertSame(
                [$first['approval']->id, $second['approval']->id, $third['approval']->id],
                array_map(static fn ($approval): string => $approval->id, $queue->entries($first['project'])),
            );

            $this->app->make(QueueReevaluation::class)->scheduleProject($first['project']);
            self::assertSame(
                [$first['approval']->id, $second['approval']->id, $third['approval']->id],
                array_map(static fn ($approval): string => $approval->id, $queue->entries($first['project'])),
            );

            $removed = $queue->remove($first['project'], $second['approval']->id, $second['approval']->version);
            self::assertSame('available', $removed->queue_state);
            self::assertNull($removed->queued_at);

            // The database stores second precision. Requeueing at the exact
            // serialized tail must still advance monotonically instead of
            // tripping the transition trigger.
            Date::setTestNow('2026-09-02 10:00:02.900000');
            $requeued = $queue->enqueue($first['project'], $removed->id, $removed->version);
            self::assertSame('queued', $requeued->queue_state);
            self::assertSame(
                [$first['approval']->id, $third['approval']->id, $second['approval']->id],
                array_map(static fn ($approval): string => $approval->id, $queue->entries($first['project'])),
            );
            self::assertSame('2026-09-02 10:00:03', $requeued->queued_at?->format('Y-m-d H:i:s'));
            self::assertSame(0, DB::table('runs')->count());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_direct_requeue_of_a_queued_entry_advances_same_second_and_later_timestamps(): void
    {
        try {
            Date::setTestNow('2026-09-02 11:00:00');
            $first = $this->completedApproval('QUEUE-DIRECT-REQUEUE-1');
            Date::setTestNow('2026-09-02 11:00:01');
            $second = $this->completedApproval('QUEUE-DIRECT-REQUEUE-2', $first['project'], $first['operator']);
            $queue = $this->app->make(ApprovalQueue::class);

            Date::setTestNow('2026-09-02 11:00:01.900000');
            $sameSecond = $queue->enqueue($first['project'], $first['approval']->id, $first['approval']->version);
            self::assertSame('2026-09-02 11:00:02', $sameSecond->queued_at?->format('Y-m-d H:i:s'));
            self::assertSame(
                [$second['approval']->id, $first['approval']->id],
                array_map(static fn ($approval): string => $approval->id, $queue->entries($first['project'])),
            );

            Date::setTestNow('2026-09-02 11:05:00');
            $later = $queue->enqueue($first['project'], $sameSecond->id, $sameSecond->version);
            self::assertSame('2026-09-02 11:05:00', $later->queued_at?->format('Y-m-d H:i:s'));
            self::assertSame($sameSecond->version + 1, $later->version);
        } finally {
            Date::setTestNow();
        }
    }
}
