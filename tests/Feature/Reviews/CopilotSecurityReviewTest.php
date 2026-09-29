<?php

namespace Tests\Feature\Reviews;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\ControlOperationRuntimeIdentity;
use App\AI6\HumanLoop\Models\HumanRequest;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Reviews\SecurityReviewEvidence;
use App\AI6\Reviews\SecurityReviewStep;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Jobs\BuildTicketApprovalPreview;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\TicketApprovalPreview;
use App\AI6\Runs\ReviewOnlyCompletionMode;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunPhase;
use App\AI6\Runs\RunType;
use App\AI6\Runs\WaitReason;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tickets\TicketUiTestCase;
use Tests\Fixtures\Agents\AgentMailboxFixture;
use Tests\Fixtures\Agents\FakeCopilotBinary;

final class CopilotSecurityReviewTest extends TicketUiTestCase
{
    use BuildsSecurityReviewFixture;

    private bool $nativeMailbox = false;

    private ?string $copilotWrappers = null;

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        if ($this->copilotWrappers !== null) {
            (new Filesystem)->deleteDirectory($this->copilotWrappers);
        }
        parent::tearDown();
    }

    protected function usesNativeProviderMailbox(): bool
    {
        return $this->nativeMailbox;
    }

    #[DataProvider('securityScenarios')]
    public function test_security_turn_reaches_the_production_mailbox_and_bound_consumer(string $scenario, ?string $status, ?string $reason): void
    {
        $this->nativeMailbox = true;
        $production = $this->app->getBindings()[AgentAdapter::class]['concrete'];
        $this->copilotWrappers = str_replace('\\', '/', storage_path('framework/testing/copilot-security-'.bin2hex(random_bytes(6))));
        $binary = FakeCopilotBinary::create($this->copilotWrappers, $scenario);
        config(['ai6.copilot.binary' => $binary, 'ai6.copilot.pinned_version' => '1.0.83',
            'ai6.agent_security_review_profile' => 'copilot-cli-review',
            'ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, $binary],
            'ai6.process.policies.agent.timeout_seconds' => $scenario === 'timeout' ? 2 : 300]);
        $prepared = $this->preparedSecurityReview('A50-'.strtoupper(str_replace('_', '-', $scenario)), ['app/Example.php' => "<?php\n\n// Bound candidate.\n"]);
        $run = $prepared['run'];
        $this->app->bind(AgentAdapter::class, $production);
        foreach ([GitHubCopilotCliConfiguration::class, GitHubCopilotCliAdapter::class, SecurityReviewStep::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        self::assertInstanceOf(GitHubCopilotCliAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'github_copilot_cli']));
        if ($scenario === 'no_evidence') {
            config(['ai6.copilot.capability_evidence' => []]);
        }
        if ($scenario === 'network') {
            config(['ai6.provider_runtime_profiles.github-copilot-cli-v1.permissions.network' => true]);
            $this->app->forgetInstance(ProviderRuntimeProfileRegistry::class);
        }
        $logs = [];
        Log::listen(static function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        $job = ExecutionJob::query()->where('run_id', $run->id)->where('step_type', 'security_review')->sole();
        $dispatch = function () use ($job): void {
            (new ExecuteRunStep($job->id))->handle(app(RunOrchestrator::class), securityReview: app(SecurityReviewStep::class));
        };
        foreach (['MAIL_PASSWORD', 'AI6_GIT_SSH_KEY'] as $key) {
            $this->previousEnvironment[$key] = getenv($key);
            putenv($key.'=worker-credential-bait-'.$key);
        }
        $dispatch();
        $claims = 0;
        AgentMailboxFixture::drain($job, $dispatch, function () use (&$claims): void {
            $claims++;
            $homes = glob(AgentExecutionProcessor::inputRoot().'/execution-*/*/home/auth', GLOB_ONLYDIR) ?: [];
            self::assertNotEmpty($homes);
            foreach ($homes as $directory) {
                self::assertSame(['.', '..'], scandir($directory), 'Worker staging must contain no provider credential bytes.');
            }
        });
        $run->refresh();
        $results = ReviewResult::query()->where('run_id', $run->id)->where('role', 'security_review')->get();
        if ($status !== null) {
            self::assertCount(1, $results, (string) HumanRequest::query()->where('run_id', $run->id)->where('kind', 'security_gate')->value('why_needed'));
            $result = $results->sole();
            self::assertSame($status, $result->result_status);
            self::assertSame($run->candidate_tree_sha, $result->candidate_tree_sha);
            self::assertSame($run->candidate_diff_hash, $result->candidate_diff_hash);
            self::assertSame($run->candidate_base_sha, $result->candidate_base_sha);
            self::assertSame('copilot-cli-review', $result->candidate_agent_profile_id);
            $artifact = RunArtifact::query()->findOrFail($result->raw_artifact_id);
            self::assertSame('provider_raw', $artifact->kind->value);
            self::assertSame(GitHubCopilotCliAdapter::USAGE_SOURCE, $artifact->redacted_metadata['usage_source']);
            self::assertSame(0, $artifact->redacted_metadata['usage']['premium_requests']);
            self::assertSame(12, $artifact->redacted_metadata['usage']['api_duration_ms']);
            self::assertGreaterThan(0, $claims);
        } else {
            self::assertCount(0, $results);
        }
        if ($status === 'clear') {
            self::assertSame(ExecutionJobState::SUCCEEDED, $job->refresh()->state);
            self::assertSame(RunPhase::PUBLISH, $run->phase);
            self::assertNotNull(app(SecurityReviewEvidence::class)->validClear($run));
        } else {
            self::assertSame(WaitReason::SECURITY_GATE, $run->wait_reason);
            self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
            $request = HumanRequest::query()->where('run_id', $run->id)->where('kind', 'security_gate')->sole();
            self::assertStringContainsString((string) $reason, $request->why_needed);
            self::assertNull(app(SecurityReviewEvidence::class)->validClear($run));
            if ($status === 'security_findings') {
                self::assertContains('security_override', $request->allowed_effects);
            }
            $logs[] = $request->toArray();
        }
        if (in_array($scenario, ['no_evidence', 'network'], true)) {
            self::assertSame(0, $claims);
            self::assertSame([], app(GitHubCopilotCliAdapter::class)->lastCommand);
            self::assertSame(0, $run->agents()->where('role', 'security_review')->count());
        }
        $rendered = json_encode($logs, JSON_THROW_ON_ERROR);
        foreach (['test-projection', 'synthetic-diagnostic-secret', 'provider failure: permission denied', 'worker-credential-bait-MAIL_PASSWORD', 'worker-credential-bait-AI6_GIT_SSH_KEY'] as $secret) {
            self::assertStringNotContainsString($secret, $rendered);
        }
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::inputRoot()));
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::outputRoot()));
    }

    /** @return list<array{string, ?string, ?string}> */
    public static function securityScenarios(): array
    {
        return [
            ['security_clear', 'clear', null],
            ['security_security_findings', 'security_findings', 'security_result_security_findings'],
            ['security_needs_human', 'needs_human', 'security_result_needs_human'],
            ['security_inconclusive', 'inconclusive', 'security_result_inconclusive'],
            ['exit_failure', null, 'security_provider_error'], ['timeout', null, 'security_provider_error'], ['output_limit', null, 'security_provider_error'],
            ['empty', null, 'security_result_invalid'], ['multiple', null, 'security_result_invalid'], ['invalid_json', null, 'security_result_invalid'],
            ['foreign_schema', null, 'security_result_invalid'], ['invalid_utf8', null, 'security_result_invalid'],
            ['no_evidence', null, 'security_agent_profile_selection_exception'], ['network', null, 'security_agent_profile_selection_exception'],
        ];
    }

    #[DataProvider('independenceCases')]
    public function test_security_reviewer_independence_is_enforced_before_snapshot_and_preview(string $implementation, RunType $type, bool $allowed): void
    {
        // A synthetic server profile exposes the alias collision; the real adapter still forbids implementation.
        $profile = config('ai6.agent_profiles.copilot-cli-review');
        $profile['roles'] = ['implementation'];
        $profile['capability_status'] = 'available';
        config(['ai6.agent_profiles.copilot-implementation-fixture' => $profile,
            'ai6.agent_profiles.copilot-cli-review.capability_status' => 'available',
            'ai6.agent_security_review_profile' => 'copilot-cli-review']);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $this->seedProviderReports();
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $read = $this->publishReadModel($actor, $project, 'tickets/A50.md', $this->validTicketMarkdown('A50'));
        $selection = new ApprovalSelection(app(AgentProfileRegistry::class)->resolve($implementation, AgentRole::IMPLEMENTATION,
            $implementation === 'fake' ? 'fake-model' : 'gpt-5.4', $implementation === 'fake' ? 'medium' : 'provider_default'),
            app(ReviewerSlotFactory::class)->fromArray([['id' => (string) Str::uuid(), 'profile' => 'fake', 'model' => 'fake-model', 'effort' => 'high', 'prompt_profile' => 'security']]),
            ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), app(AgentInputLimits::class)),
            $actor->getKey(), 'manual', $type, $type === RunType::REVIEW_ONLY ? 'bound-source' : null,
            $type === RunType::REVIEW_ONLY ? ReviewOnlyCompletionMode::MANUAL : null);
        try {
            $snapshot = app(ApprovalSnapshotFactory::class)->create($project, $read, $selection, (string) Str::uuid());
            self::assertTrue($allowed);
            self::assertSame('copilot-cli-review', $snapshot->agentProfiles['security_reviewer']['profile_id']);
        } catch (\InvalidArgumentException $exception) {
            self::assertFalse($allowed);
            self::assertSame('Der Securityreviewer darf im Implementierungslauf nicht das Providerprofil des Implementierungsslots verwenden.', $exception->getMessage());
        }
        if (! $allowed) {
            $preview = TicketApprovalPreview::query()->create(['id' => (string) Str::uuid(), 'project_id' => $project->id,
                'ticket_read_model_id' => $read->id, 'reviewed_ticket_blob_sha' => $read->blob_sha,
                'reviewed_control_sha' => $read->control_commit, 'ticket_contract_sha256' => $read->ticket_contract_sha256,
                'control_generation' => $read->control_generation, 'selection_snapshot' => $selection->jsonSerialize(),
                'state' => 'queued', 'requested_by' => $actor->id]);
            $this->app->instance(ControlOperationRuntimeIdentity::class, new ControlOperationRuntimeIdentity('worker', 'testing'));
            $this->app->call([new BuildTicketApprovalPreview($preview->id), 'handle']);
            self::assertSame('conflict', $preview->refresh()->state);
            self::assertSame('preview_snapshot_failed', $preview->error_code);
            self::assertNull($preview->approval_snapshot);
        }
    }

    /** @return list<array{string, RunType, bool}> */
    public static function independenceCases(): array
    {
        return [['copilot-implementation-fixture', RunType::IMPLEMENTATION, false], ['fake', RunType::IMPLEMENTATION, true],
            ['copilot-implementation-fixture', RunType::REVIEW_ONLY, true]];
    }
}
