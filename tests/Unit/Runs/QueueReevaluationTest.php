<?php

namespace Tests\Unit\Runs;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\EvaluateTicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Runs\QueueReevaluation;
use App\AI6\Runs\QueueReevaluationTrigger;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Runs\BuildsFinalizedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class QueueReevaluationTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @return list<InstructionCandidate> */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    public function test_external_run_008_triggers_schedule_the_real_decision_and_observe_changed_state(): void
    {
        $fixture = $this->completedApproval('QUEUE-REEVALUATION-1');
        $reevaluation = $this->app->make(QueueReevaluation::class);

        $eligibleTriggers = [
            QueueReevaluationTrigger::FETCH,
            QueueReevaluationTrigger::READ_MODEL_REFRESH,
            QueueReevaluationTrigger::TICKET_CHANGE,
            QueueReevaluationTrigger::CONFIG_CHANGE,
        ];
        foreach ($eligibleTriggers as $trigger) {
            $reevaluation->afterExternalEffect($fixture['project']->refresh(), $trigger);
            $evaluation = $this->evaluateQueued($fixture['approval']->id);
            self::assertTrue($evaluation->eligible, $trigger->value);
        }

        $fixture['project']->forceFill(['control_generation' => 1])->save();
        $ineligibleTriggers = [
            QueueReevaluationTrigger::APPROVAL_REVOCATION,
            QueueReevaluationTrigger::DEPENDENCY_STATUS_CHANGE,
            QueueReevaluationTrigger::RUN_COMPLETION,
            QueueReevaluationTrigger::QUEUE_INTERVENTION,
        ];
        foreach ($ineligibleTriggers as $trigger) {
            $reevaluation->afterExternalEffect($fixture['project']->refresh(), $trigger);
            $evaluation = $this->evaluateQueued($fixture['approval']->id);
            self::assertFalse($evaluation->eligible, $trigger->value);
            self::assertContains('control_generation_changed', $evaluation->reasons, $trigger->value);
        }
    }

    public function test_prompt_capability_and_security_changes_use_the_production_binding_scheduler(): void
    {
        $fixture = $this->completedApproval('QUEUE-TRUSTED-BINDINGS-1');
        Cache::forget('ai6.queue.trusted-bindings.sha256');

        $this->app->make(QueueReevaluation::class)->scheduleTrustedBindingChanges();

        self::assertTrue($this->evaluateQueued($fixture['approval']->id)->eligible);
    }

    private function evaluateQueued(string $approvalId): TicketApprovalEvaluation
    {
        $evaluation = TicketApprovalEvaluation::query()
            ->where('ticket_approval_id', $approvalId)
            ->where('state', 'queued')
            ->firstOrFail();
        $this->app->call([new EvaluateTicketApproval($evaluation->id), 'handle']);
        $evaluation->refresh();

        self::assertSame('ready', $evaluation->state);

        return $evaluation;
    }
}
