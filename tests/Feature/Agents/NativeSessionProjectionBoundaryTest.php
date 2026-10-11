<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CredentialProjection;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\InstructionProfileRegistry;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Git\CanonicalJson;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Shared\Process\AgentProcessScope;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Agents\BuildsProviderOnboarding;
use Tests\Fixtures\Agents\NativeProviderMailbox;
use Tests\TestCase;

/** Real child-mount probes in the shipped AppArmor/Seccomp Linux harness. */
final class NativeSessionProjectionBoundaryTest extends TestCase
{
    use BuildsProviderOnboarding;

    private ?ExecutionHome $home = null;

    private string $export;

    private string $input;

    private string $output;

    private ExecutionHomeManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux' || ! getenv('AI6_NATIVE_PROVIDER_TEST_RUNNER')) {
            self::markTestSkipped('Requires the shipped Linux AppArmor/Seccomp test harness.');
        }
        config(['ai6.runtime_role' => 'agent']);
        $id = bin2hex(random_bytes(16));
        $this->input = '/var/lib/ai6/agent-executions/execution-'.$id;
        $this->output = '/var/lib/ai6/agent-outputs/execution-'.$id;
        $this->export = sys_get_temp_dir().'/ai6-session-boundary-'.$id;
        foreach ([$this->input, $this->output, $this->export] as $path) {
            self::assertTrue(mkdir($path, 0700));
        }
        file_put_contents($this->export.'/example.txt', 'Original');
        $runtime = app(ProviderRuntimeProfileRegistry::class)->get('github-copilot-cli-v1');
        $snapshot = new InstructionSnapshot('github_copilot_cli', [], str_repeat('b', 64));
        $context = new AgentResultContext(AgentRole::QUALITY_REVIEW, new PromptSnapshot('1', [], ['quality_review' => 'Synthetic mount test.'], str_repeat('a', 64)),
            $snapshot, $runtime, [], '', slotId: 'boundary', model: 'auto', effort: 'provider_default');
        $this->manager = new ExecutionHomeManager(app(CanonicalJson::class), new CredentialRevisionRegistry(['github_copilot_cli' => 'test-v1']), app(ProviderRuntimeProfileRegistry::class));
        $this->home = $this->manager->create($this->input, $this->output, 'boundary', 'review', $this->export,
            app(InstructionProfileRegistry::class)->get('github_copilot_cli'), $snapshot, $runtime,
            new CredentialProjection('github_copilot_cli', 'test-v1', []), turnContext: $context);
        $session = $this->home->nativeSessionProjection;
        self::assertNotNull($session);
        self::assertTrue(mkdir(dirname(dirname($session->source)), 0700));
        self::assertTrue(mkdir(dirname($session->source), 0700));
        self::assertTrue(mkdir($session->source, 0700));
        self::assertTrue(mkdir(dirname(dirname($session->source)).'/artifacts', 0700));
    }

    protected function tearDown(): void
    {
        if ($this->home !== null) {
            $this->home->nativeSessionProjection?->cleanup();
            $this->manager->destroy($this->home);
            rmdir($this->input);
            rmdir($this->output);
            unlink($this->export.'/example.txt');
            rmdir($this->export);
        }
        parent::tearDown();
    }

    #[DataProvider('childBindings')]
    public function test_child_verifies_the_real_mount_before_starting_the_payload(string $binding, bool $expected): void
    {
        self::assertNotNull($this->home);
        $session = $this->home->nativeSessionProjection;
        self::assertNotNull($session);
        $scope = new AgentProcessScope([$this->home->root], [dirname($session->source), dirname(dirname($session->source)).'/artifacts'], nativeSessionProjection: $session);
        $request = new ProcessRequest([PHP_BINARY, '-r', 'echo "verified-provider-start";'], $this->home->workspace, [], [], new RedactionContext('test', null, 'native-session'));
        $command = $scope->command($request);
        $wrapper = array_search($session::WRAPPER, $command, true);
        self::assertIsInt($wrapper);
        if ($binding === 'inode') {
            $command[$wrapper + 3] = '0:0';
        } elseif ($binding === 'context') {
            $command[$wrapper + 4] = str_repeat('0', 64);
        } elseif ($binding === 'runtime') {
            $command[$wrapper + 5] = str_repeat('0', 64);
        } elseif ($binding === 'role') {
            $command[$wrapper + 6] = 'finding_verification';
        } elseif ($binding === 'extra_input_mount') {
            array_splice($command, $wrapper - 1, 0, ['--bind', dirname($session->source), $this->home->root.'/runtime']);
        } elseif ($binding === 'nonempty_source') {
            file_put_contents($session->source.'/foreign.jsonl', 'foreign history');
        }
        // No inherited environment, credentials, prompt or application bootstrap.
        $environment = array_fill_keys(array_keys(getenv()), false);
        $process = new Process($command, $this->home->workspace, $environment, null, 10);
        $process->run();
        self::assertSame($expected, $process->isSuccessful());
        self::assertSame($expected ? 'verified-provider-start' : '', $process->getOutput());
        self::assertSame($binding === 'nonempty_source' ? ['.', '..', 'foreign.jsonl'] : ['.', '..'], scandir($session->source));
        self::assertSame(['.', '..'], scandir($session->target));
        self::assertSame('Original', file_get_contents($this->home->workspace.'/example.txt'));
    }

    /** @return list<array{string, bool}> */
    public static function childBindings(): array
    {
        return [['valid', true], ['inode', false], ['context', false], ['runtime', false],
            ['role', false], ['extra_input_mount', false], ['nonempty_source', false]];
    }

    public function test_scope_exit_stops_an_unfinished_payload_before_releasing_cleanup_authority(): void
    {
        self::assertNotNull($this->home);
        $this->createOnboardingFixture();
        $result = NativeProviderMailbox::probeSessionScope($this->home, false);
        self::assertFalse($result['rejected']);
        self::assertFalse($result['running']);
        self::assertTrue($result['quiescent']);
        self::assertTrue($result['released']);
    }

    public function test_unconfirmed_termination_never_releases_cleanup_authority(): void
    {
        self::assertNotNull($this->home);
        $this->createOnboardingFixture();
        $result = NativeProviderMailbox::probeSessionScope($this->home, true);
        self::assertTrue($result['rejected']);
        self::assertSame('The provider process end could not be confirmed.', $result['message']);
        self::assertFalse($result['released']);
        self::assertFalse($result['quiescent']);
        self::assertDirectoryExists($this->home->nativeSessionProjection->source);
    }
}
