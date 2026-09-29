<?php

namespace Tests\Fixtures\Agents;

use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\CodexCliAdapter;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\ProviderOnboarding;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/** Actual agent process with read-only inputs; no invented mount observations. */
final class NativeProviderMailbox
{
    public const CONTAINER_INPUT = '/var/lib/ai6/agent-executions';

    public const CONTAINER_OUTPUT = '/var/lib/ai6/agent-outputs';

    public const CONTAINER_PRIVATE = '/run/ai6/provider-private';

    public const CONTAINER_BINARIES = '/usr/local/lib/ai6-test-binaries';

    public static int $capabilityProbes = 0;

    /** Optional test-only launcher; the child still checks real kernel confinement and mounts. */
    public static function containerRunner(): ?string
    {
        $path = getenv('AI6_NATIVE_PROVIDER_TEST_RUNNER');
        if ($path === false || $path === '') {
            return null;
        }
        Assert::assertSame('Linux', PHP_OS_FAMILY);
        Assert::assertTrue(str_starts_with($path, '/') && is_file($path) && ! is_link($path) && realpath($path) === $path);
        Assert::assertFalse(is_writable($path), 'The external test launcher must be mounted read-only.');

        return $path;
    }

    public static function resetContainerVolumes(): void
    {
        $runner = self::containerRunner();
        Assert::assertNotNull($runner);
        $identity = getenv('AI6_NATIVE_PROVIDER_TEST_ID');
        Assert::assertIsString($identity);
        Assert::assertMatchesRegularExpression('/\A[a-z0-9-]{8,64}\z/', $identity);
        $manifestPath = dirname($runner).'/volume-identities.json';
        Assert::assertFalse(is_writable($manifestPath));
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 8, JSON_THROW_ON_ERROR);
        Assert::assertSame($identity, $manifest['identity']);
        $paths = [self::CONTAINER_INPUT, self::CONTAINER_OUTPUT, self::CONTAINER_PRIVATE];
        foreach ($paths as $path) {
            Assert::assertSame($path, realpath($path));
            Assert::assertSame($manifest['devices'][$path], stat($path)['dev'], 'Only the dedicated test mounts may be reset.');
            Assert::assertNotSame(stat('/')['dev'], stat($path)['dev']);
        }
        foreach ($paths as $path) {
            Assert::assertTrue((new Filesystem)->cleanDirectory($path));
        }
    }

    public static function processNext(bool $missingAnswer = false): bool
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            Assert::markTestSkipped('Native provider mailbox requires Linux namespaces.');
        }
        $input = AgentExecutionProcessor::inputRoot();
        $output = AgentExecutionProcessor::outputRoot();
        $configuration = $input.'/native-supervisor-test.json';
        $settings = [];
        foreach (['ai6.agent_profiles', 'ai6.provider_runtime_profiles', 'ai6.instruction_profiles', 'ai6.process',
            'ai6.execution_mailboxes', 'ai6.provider_onboarding', 'ai6.codex', 'ai6.grok', 'ai6.copilot', 'ai6.credential_revisions'] as $key) {
            $settings[$key] = config($key);
        }
        $settings = [...$settings, 'ai6.runtime_role' => 'agent', 'app.env' => 'testing', 'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'logging.default' => 'stderr',
            'database.connections.sqlite.database' => '/nonexistent/agent-has-no-database',
            'ai6.fixture.missing_answer' => $missingAnswer,
            'ai6.fixture.expire_provider_report' => config('ai6.fixture.expire_provider_report', false)];
        file_put_contents($configuration, json_encode($settings, JSON_THROW_ON_ERROR));
        $arguments = ['/usr/bin/bwrap', '--die-with-parent', '--unshare-user', '--unshare-pid', '--unshare-ipc', '--unshare-uts',
            '--cap-drop', 'ALL', '--new-session', '--ro-bind', '/', '/', '--proc', '/proc', '--dev', '/dev',
            '--tmpfs', '/tmp', '--ro-bind', $input, $input, '--bind', $output, $output];
        foreach (['store_root', 'report_root', 'presence_root', 'private_root'] as $key) {
            $path = ProviderOnboarding::path($key);
            array_push($arguments, '--bind', $path, $path);
        }
        array_push($arguments, '--', PHP_BINARY, '-d', 'zend.exception_ignore_args=1', __DIR__.'/provider-supervisor.php', $configuration);
        if (($runner = self::containerRunner()) !== null) {
            // Docker supplies the supervisor's read-only mailbox mount. The
            // provider's inner AgentProcessScope remains unchanged.
            $arguments = [PHP_BINARY, $runner, $configuration];
        }
        $process = new Process($arguments, base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(120);
        try {
            Assert::assertSame(0, $process->run(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            Assert::assertIsArray($result);
            Assert::assertIsBool($result['processed']);
            Assert::assertIsInt($result['capability_probes']);
            self::$capabilityProbes = $result['capability_probes'];
            Assert::assertIsArray($result['commands']);
            foreach ($result['commands'] as $adapter => $command) {
                Assert::assertIsString($adapter);
                Assert::assertContains($adapter, [CodexCliAdapter::class, GrokCliAdapter::class, GitHubCopilotCliAdapter::class]);
                Assert::assertIsArray($command);
                app($adapter)->lastCommand = $command;
            }

            return $result['processed'];
        } finally {
            $process->stop(1);
            unlink($configuration);
        }
    }
}
