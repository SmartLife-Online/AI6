<?php

namespace Tests\Unit\Runs;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\InstructionCandidate;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\EvaluateTicketApproval;
use App\AI6\Runs\Models\TicketApprovalEvaluation;
use App\AI6\Runs\QueueReevaluation;
use App\AI6\Runs\QueueReevaluationTrigger;
use App\AI6\Shared\Config\StrictEnumParser;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Runs\BuildsFinalizedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;
use Tests\Fixtures\Agents\BuildsProviderOnboarding;

final class QueueReevaluationTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;
    use BuildsProviderOnboarding;

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

    public function test_new_provider_report_and_rotation_reschedule_queued_projects_in_a_booted_scheduler(): void
    {
        $fixture = $this->completedApproval('QUEUE-PROVIDER-REPORT-1');
        config(['ai6.agent_profiles.copilot-cli-review.capability_status' => 'available']);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $this->seedProviderReports();
        $registry = AgentProfileRegistry::fromConfiguredValues(app(StrictEnumParser::class));
        $this->app->instance(AgentProfileRegistry::class, $registry);
        $scheduler = $this->app->make(QueueReevaluation::class);
        Cache::forget('ai6.queue.trusted-bindings.sha256');
        $scheduler->scheduleTrustedBindingChanges();
        $this->evaluateQueued($fixture['approval']->id);
        $scheduler->scheduleTrustedBindingChanges();
        self::assertSame(0, TicketApprovalEvaluation::query()->where('state', 'queued')->count());

        $reports = app(ProviderCapabilityReport::class);
        $document = $reports->read('github_copilot_cli');
        self::assertNotNull($document);
        $store = app(ProviderCredentialStore::class);
        config(['ai6.runtime_role' => 'agent']);
        $store->locked(fn () => $store->publish('github_copilot_cli', $document['generation'], $document['rows'], $document['checked_at'] - 1, $document['boot_id']));
        config(['ai6.runtime_role' => 'scheduler']);
        $scheduler->scheduleTrustedBindingChanges();
        self::assertSame(0, TicketApprovalEvaluation::query()->where('state', 'queued')->count(), 'A timestamp-only refresh must not schedule another evaluation.');
        $rows = $document['rows'];
        $rows[0]['status'] = 'degraded';
        $rows[0]['reason'] = 'probe';
        config(['ai6.runtime_role' => 'agent']);
        $store->locked(fn () => $store->publish('github_copilot_cli', $document['generation'], $rows, time(), $document['boot_id']));
        $changed = $reports->read('github_copilot_cli');
        self::assertNotNull($changed);
        self::assertSame($rows, $changed['rows']);
        config(['ai6.runtime_role' => 'scheduler']);
        $scheduler->scheduleTrustedBindingChanges();
        self::assertSame(1, TicketApprovalEvaluation::query()->where('state', 'queued')->count());
        $this->evaluateQueued($fixture['approval']->id);
        config(['ai6.runtime_role' => 'agent']);
        $store->replace('github_copilot_cli', 'rotated-synthetic-token');
        $generation = $store->generation('github_copilot_cli');
        self::assertNotSame($document['generation'], $generation);
        $store->locked(fn () => $store->publish('github_copilot_cli', $generation, $rows, time(), $document['boot_id']));
        config(['ai6.runtime_role' => 'scheduler']);
        $scheduler->scheduleTrustedBindingChanges();
        self::assertSame(1, TicketApprovalEvaluation::query()->where('state', 'queued')->count());
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
