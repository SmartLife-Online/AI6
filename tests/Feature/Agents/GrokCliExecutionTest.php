<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\AgentExecutionRunner;
use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\GrokCliConfiguration;
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
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityProfile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Reviews\BuildsReviewRoundFixture;
use Tests\Feature\Tickets\TicketUiTestCase;
use Tests\Fixtures\Agents\AgentMailboxFixture;
use Tests\Fixtures\Agents\FakeGrokBinary;

class GrokCliExecutionTest extends TicketUiTestCase
{
    use BuildsReviewRoundFixture;

    private string $wrappers;

    protected function usesNativeProviderMailbox(): bool
    {
        return true;
    }

    protected string $grokProfile = 'grok-cli-review';

    protected string $grokModel = 'provider_default';

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Grok requires native Linux links.');
        }
        $this->wrappers = str_replace('\\', '/', storage_path('framework/testing/grok-mailbox-'.bin2hex(random_bytes(6))));
    }

    protected function tearDown(): void
    {
        if (! isset($this->wrappers)) {
            parent::tearDown();

            return;
        }
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
        $this->seedProviderReports();
        $this->reviewSlotIds = [(string) Str::uuid(), (string) Str::uuid()];

        return new ApprovalSelection(app(AgentProfileRegistry::class)->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            app(ReviewerSlotFactory::class)->fromArray(array_map(fn (string $id, string $promptProfile): array => [
                'id' => $id, 'profile' => $this->grokProfile, 'model' => $this->grokModel, 'effort' => 'provider_default', 'prompt_profile' => $promptProfile,
            ], $this->reviewSlotIds, ['tests', 'security'])), ApprovalLimits::fromConfiguredValues(config('ai6.project_config.server_defaults.limits'), app(AgentInputLimits::class)), $attentionUser?->getKey(), 'manual');
    }

    #[DataProvider('scenarios')]
    public function test_production_binding_reaches_mailbox_consumer_and_artifact(string $scenario, string $state, bool $direct = false): void
    {
        Mail::fake();
        // Keep the exact shipped resolver while the existing setup fixture temporarily selects its fake.
        $production = $this->app->getBindings()[AgentAdapter::class]['concrete'];
        $binary = FakeGrokBinary::create($this->wrappers, $scenario);
        $configuration = new GrokCliConfiguration($binary, '1.0.5');
        config(['ai6.grok.binary' => $binary, 'ai6.grok.pinned_version' => '1.0.5',
            'ai6.grok.capability_evidence' => [$configuration->evidenceKey(app(ProviderRuntimeProfileRegistry::class)->get('grok-cli-v1'), AgentRole::QUALITY_REVIEW, $this->grokModel, 'provider_default')],
            'ai6.agent_profiles.'.$this->grokProfile.'.capability_status' => 'available',
            'ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, $binary],
            'ai6.process.policies.agent.timeout_seconds' => $scenario === 'timeout' ? 2 : 300,
        ]);
        foreach ([GrokCliConfiguration::class, GrokCliAdapter::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $prepared = $this->preparedReviewRun('AI6-041-'.strtoupper(str_replace('_', '-', $scenario)));
        // The shared fixture assigns its isolated mailbox roots during preparation.
        // Evidence must bind those final roots, just as the production configuration does.
        config(['ai6.grok.capability_evidence' => [$configuration->evidenceKey(app(ProviderRuntimeProfileRegistry::class)->get('grok-cli-v1'), AgentRole::QUALITY_REVIEW, $this->grokModel, 'provider_default')]]);
        foreach ([GrokCliConfiguration::class, GrokCliAdapter::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $this->app->bind(AgentAdapter::class, $production);
        self::assertInstanceOf(GrokCliAdapter::class, $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'grok_cli']));
        if ($direct) {
            $strict = app(SecurityPolicy::class);
            $measures = $strict->measures();
            $measures[SecurityMeasure::REQUIRE_AGENT_SANDBOX->value] = false;
            $this->app->instance(SecurityPolicy::class, new SecurityPolicy(SecurityProfile::CUSTOM, $measures, true));
            $this->app->forgetInstance(AgentExecutionRunner::class);

        }
        $job = ExecutionJob::query()->where('run_id', $prepared['run']->id)->where('step_type', 'review')->sole();
        $dispatch = function () use ($job): void {
            (new ExecuteRunStep($job->id))->handle(app(RunOrchestrator::class), reviews: app(ReviewRound::class));
        };
        $dispatch();
        if ($direct) {
            self::assertSame(ExecutionJobState::WAITING, $job->refresh()->state);
            self::assertSame('provider_error', $prepared['run']->fresh()->wait_reason?->value);
            self::assertSame([], glob(AgentExecutionProcessor::inputRoot().'/requests/*'));
            self::assertSame([], app(GrokCliAdapter::class)->lastCommand);

            return;
        }
        AgentMailboxFixture::drain($job, $dispatch, function () use ($scenario): void {
            foreach (glob(AgentExecutionProcessor::inputRoot().'/execution-*/*/home/auth', GLOB_ONLYDIR) ?: [] as $directory) {
                self::assertSame(['.', '..'], scandir($directory), 'The worker received no credentials.');
            }
            $auth = $this->onboardingRoot.'/store/grok_cli/token';
            if ($scenario === 'missing_auth' && is_file($auth)) {
                unlink($auth);
            }
        });
        $results = ReviewResult::query()->where('run_id', $prepared['run']->id)->get();
        self::assertNotEmpty($results, (string) $job->refresh()->failure_code);
        if ($state === 'ok') {
            self::assertSame(ExecutionJobState::SUCCEEDED, $job->refresh()->state, json_encode([$job->failure_code, $results->pluck('failure_code')->all(), RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->pluck('redacted_metadata')->all()], JSON_THROW_ON_ERROR));
            self::assertCount(2, $results);
            self::assertSame([ReviewInvocationOutcome::VALID_RESULT], $results->pluck('invocation_outcome')->unique()->values()->all());
            self::assertCount(2, $results->pluck('session_id')->unique());
        }
        $artifacts = RunArtifact::query()->where('run_id', $prepared['run']->id)->where('kind', 'provider_raw')->get();
        $grokArtifacts = $artifacts->filter(fn (RunArtifact $artifact): bool => in_array($artifact->redacted_metadata['slot_id'] ?? '', $this->reviewSlotIds, true));
        self::assertNotEmpty($grokArtifacts);
        foreach ($grokArtifacts as $artifact) {
            // The review consumer owns schema validation. A syntactically valid
            // foreign schema retains raw transport state "ok" in the existing artifact contract.
            self::assertSame($scenario === 'foreign_schema' ? 'ok' : $state, $artifact->redacted_metadata['validation_state'] ?? $artifact->redacted_metadata['state']);
            if (in_array($scenario, ['success', 'missing_answer', 'invalid_json', 'foreign_schema', 'multiple', 'multiple_missing_usage', 'null_usage', 'cleanup_invalid'], true)) {
                self::assertSame(GrokCliAdapter::USAGE_SOURCE, $artifact->redacted_metadata['usage_source']);
                self::assertSame($scenario === 'null_usage' ? null : 12, $artifact->redacted_metadata['usage']['input_tokens']);
            }
            if ($scenario === 'missing_usage') {
                self::assertSame('unknown', $artifact->redacted_metadata['usage_source']);
                self::assertSame([], $artifact->redacted_metadata['usage']);
            }
            if (in_array($scenario, ['all-zero', 'all-null'], true)) {
                self::assertSame(GrokCliAdapter::USAGE_SOURCE, $artifact->redacted_metadata['usage_source']);
                self::assertSame(['num_turns' => 2], $artifact->redacted_metadata['usage']);
            }
            if ($scenario === 'positive_cost') {
                self::assertSame(0.25, $artifact->redacted_metadata['usage']['cost_usd']);
                self::assertArrayNotHasKey('api_duration_ms', $artifact->redacted_metadata['usage']);
            }
            if ($scenario === 'zero_tokens_cost') {
                self::assertSame(['cost_usd' => 0.25, 'num_turns' => 2], $artifact->redacted_metadata['usage']);
            }
            if (in_array($scenario, ['success', 'invalid_json', 'cleanup_invalid', 'max_turns'], true)) {
                self::assertSame(2, $artifact->redacted_metadata['usage']['num_turns']);
            }
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', (string) $artifact->execution_id);
        }
        if ($scenario === 'foreign_schema') {
            self::assertSame(['invalid_json'], $results->pluck('invocation_outcome')->map(static fn (ReviewInvocationOutcome $outcome): string => $outcome->value)->unique()->values()->all());
        }
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::inputRoot()));
        self::assertSame([], $this->directoryEntries(AgentExecutionProcessor::outputRoot()));
    }

    /** @return list<array{0: string, 1: string, 2?: bool}> */
    public static function scenarios(): array
    {
        return [['all-zero', 'ok'], ['all-zero', 'ok', true], ['all-null', 'ok'], ['positive_cost', 'ok'], ['zero_tokens_cost', 'ok'],
            ['init_tools', 'invalid_json'], ['web_search', 'invalid_json'], ['max_turns', 'invalid_json'],
            ['cleanup_invalid', 'invalid_json'], ['cleanup_invalid', 'invalid_json', true],
            ['success', 'ok'], ['null_usage', 'ok'], ['missing_usage', 'ok'], ['empty', 'invalid_json'], ['multiple', 'invalid_json'],
            ['invalid_json', 'invalid_json'], ['foreign_schema', 'invalid_json'], ['invalid_utf8', 'invalid_json'],
            ['exit_failure', 'provider_error'], ['timeout', 'provider_error'], ['output_limit', 'provider_error'], ['missing_auth', 'provider_error'],
            ['missing_answer', 'invalid_json'], ['missing_answer', 'invalid_json', true],
            ['multiple_missing_usage', 'invalid_json'], ['multiple_missing_usage', 'invalid_json', true],
            ['invalid_json', 'invalid_json', true], ['multiple', 'invalid_json', true], ['null_usage', 'ok', true], ['missing_usage', 'ok', true]];
    }
}
