<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Auth\Models\User;
use App\AI6\Reviews\Models\ReviewResult;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Reviews\ReviewInvocationOutcome;
use App\AI6\Reviews\ReviewRound;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Jobs\ExecuteRunStep;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunOrchestrator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;
use Tests\Fixtures\Agents\AgentMailboxFixture;
use Tests\Fixtures\Agents\FakeCopilotBinary;

class GitHubCopilotCliExecutionTest extends TicketUiTestCase
{
    use BuildsReviewRoundFixture;

    private string $wrappers;

    protected string $copilotProfile = 'copilot-cli-review';

    protected string $copilotModel = 'gpt-5.4';

    protected function setUp(): void
    {
        parent::setUp();
        $this->wrappers = str_replace('\\', '/', storage_path('framework/testing/copilot-mailbox-'.bin2hex(random_bytes(6))));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->wrappers.'/*') ?: [] as $path) {
            unlink($path);
        }
        if (is_dir($this->wrappers)) {
            rmdir($this->wrappers);
        }
        parent::tearDown();
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        $this->reviewSlotIds = [(string) Str::uuid(), (string) Str::uuid()];

        return new ApprovalSelection(app(AgentProfileRegistry::class)->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            app(ReviewerSlotFactory::class)->fromArray(array_map(fn (string $id, string $promptProfile): array => [
                'id' => $id, 'profile' => $this->copilotProfile, 'model' => $this->copilotModel, 'effort' => 'provider_default', 'prompt_profile' => $promptProfile,
            ], $this->reviewSlotIds, ['tests', 'security'])), ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), app(AgentInputLimits::class)), $attentionUser?->getKey(), 'manual');
    }

    #[DataProvider('scenarios')]
    public function test_production_binding_reaches_mailbox_consumer_and_artifact(string $scenario, string $state): void
    {
        Mail::fake();
        // Keep the exact shipped resolver while the existing setup fixture temporarily selects its fake.
        $production = $this->app->getBindings()[AgentAdapter::class]['concrete'];
        $binary = FakeCopilotBinary::create($this->wrappers, $scenario);
        $configuration = new GitHubCopilotCliConfiguration($binary, '1.0.83');
        config(['ai6.copilot.binary' => $binary, 'ai6.copilot.pinned_version' => '1.0.83',
            'ai6.copilot.capability_evidence' => [$configuration->evidenceKey(app(ProviderRuntimeProfileRegistry::class)->get('github-copilot-cli-v1'), AgentRole::QUALITY_REVIEW, $this->copilotModel, 'provider_default')],
            'ai6.agent_profiles.'.$this->copilotProfile.'.capability_status' => 'available',
            'ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, $binary],
            'ai6.process.policies.agent.timeout_seconds' => $scenario === 'timeout' ? 2 : 300,
        ]);
        foreach ([GitHubCopilotCliConfiguration::class, GitHubCopilotCliAdapter::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $prepared = $this->preparedReviewRun('AI6-048-'.strtoupper(str_replace('_', '-', $scenario)));
        $this->app->bind(AgentAdapter::class, $production);
        self::assertInstanceOf(GitHubCopilotCliAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'github_copilot_cli']));
        $job = ExecutionJob::query()->where('run_id', $prepared['run']->id)->where('step_type', 'review')->sole();
        $dispatch = function () use ($job): void {
            (new ExecuteRunStep($job->id))->handle(app(RunOrchestrator::class), reviews: app(ReviewRound::class));
        };
        $dispatch();
        AgentMailboxFixture::drain($job, $dispatch, static function () use ($scenario): void {
            // Test-only insertion at the future AI6-035 projection point, before the mailbox claim.
            if ($scenario === 'missing_auth') {
                return;
            }
            foreach (glob(AgentExecutionProcessor::inputRoot().'/execution-*/*/home/auth', GLOB_ONLYDIR) ?: [] as $directory) {
                chmod($directory, 0700);
                $projection = implode(DIRECTORY_SEPARATOR, [$directory, 'token']);
                file_put_contents($projection, 'test-projection');
                chmod($projection, 0440);
                chmod($directory, 0550);
            }
        });
        $results = ReviewResult::query()->where('run_id', $prepared['run']->id)->get();
        self::assertNotEmpty($results, (string) $job->refresh()->failure_code);
        if ($state === 'ok') {
            self::assertSame(ExecutionJobState::SUCCEEDED, $job->refresh()->state);
            self::assertCount(2, $results);
            self::assertSame([ReviewInvocationOutcome::VALID_RESULT], $results->pluck('invocation_outcome')->unique()->values()->all());
            self::assertCount(2, $results->pluck('session_id')->unique());
        }
        $artifacts = RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->get();
        $copilotArtifacts = $artifacts->filter(fn (RunArtifact $artifact): bool => in_array($artifact->redacted_metadata['slot_id'] ?? '', $this->reviewSlotIds, true));
        self::assertNotEmpty($copilotArtifacts);
        foreach ($copilotArtifacts as $artifact) {
            // The review consumer owns schema validation. A syntactically valid
            // foreign schema retains raw transport state "ok" in the existing artifact contract.
            self::assertSame($scenario === 'foreign_schema' ? 'ok' : $state, $artifact->redacted_metadata['validation_state'] ?? $artifact->redacted_metadata['state']);
            if (in_array($scenario, ['success', 'invalid_json', 'foreign_schema', 'multiple', 'null_usage'], true)) {
                self::assertSame(GitHubCopilotCliAdapter::USAGE_SOURCE, $artifact->redacted_metadata['usage_source']);
                self::assertSame($scenario === 'null_usage' ? null : 0, $artifact->redacted_metadata['usage']['premium_requests']);
            }
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', (string) $artifact->execution_id);
        }
        if ($scenario === 'foreign_schema') {
            self::assertSame(['invalid_json'], $results->pluck('invocation_outcome')->map(static fn (ReviewInvocationOutcome $outcome): string => $outcome->value)->unique()->values()->all());
        }
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::inputRoot()));
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::outputRoot()));
    }

    /** @return list<array{string, string}> */
    public static function scenarios(): array
    {
        return [['success', 'ok'], ['null_usage', 'ok'], ['missing_usage', 'ok'], ['empty', 'invalid_json'], ['multiple', 'invalid_json'],
            ['invalid_json', 'invalid_json'], ['foreign_schema', 'invalid_json'], ['invalid_utf8', 'invalid_json'],
            ['exit_failure', 'provider_error'], ['timeout', 'provider_error'], ['output_limit', 'provider_error'], ['missing_auth', 'provider_error']];
    }
}
