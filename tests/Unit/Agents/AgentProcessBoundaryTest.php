<?php

namespace Tests\Unit\Agents;

use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessLimits;
use App\AI6\Shared\Process\ProcessPolicy;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use Tests\TestCase;

final class AgentProcessBoundaryTest extends TestCase
{
    public function test_agent_policy_and_real_child_environment_exclude_foreign_access(): void
    {
        $root = dirname(__DIR__, 3);
        $forbidden = ['APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'AI6_GIT_SSH_KEY', 'AI6_GIT_KNOWN_HOSTS', 'SESSION_DRIVER'];
        $configured = ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::AGENT);
        self::assertSame([PHP_BINARY], $configured->allowedExecutables, 'The shipped agent policy names the FakeAgent executable.');
        foreach ($forbidden as $name) {
            self::assertNotContains($name, $configured->environmentAllowlist);
            putenv($name.'=must-not-pass');
        }

        try {
            $policy = new ProcessPolicy(ProcessPolicyName::AGENT, 5, 4096, [PHP_BINARY], ['AI6_RUNTIME_PROFILE'], [$root], false, 100);
            $control = new ProcessPolicy(ProcessPolicyName::CONTROL, 5, 4096, [PHP_BINARY], [], [$root], false, 100);
            $checker = new ProcessPolicy(ProcessPolicyName::CHECKER, 5, 4096, [], [], [$root], false, 100);
            $limits = new ProcessLimits(5, 4096, 4, 10, 1024, 10);
            $registry = new ProcessPolicyRegistry(['control' => $control, 'agent' => $policy, 'checker' => $checker], $limits);
            $configuration = new ProcessConfiguration(5, 4096, 100, 2, $root.'/app/AI6/Shared/Process/control-process-wrapper.sh', '/bin/sh', null, null, $root.'/storage/framework/testing/missing-locks', 1, 100, 0);
            $boundary = new class implements ProcessIsolationBoundary
            {
                public function assertIsolated(ProcessRequest $request, ProcessPolicy $policy): void {}
            };
            $runner = new ControlProcessRunner($configuration, $this->redactor(), new EffectLock($configuration), $registry, $boundary);
            $code = 'echo json_encode(array_map(static fn($key) => getenv($key) === false ? "missing" : "present", array_slice($argv, 1)));';
            $result = $runner->run(new ProcessRequest(
                [PHP_BINARY, '-r', $code, ...$forbidden],
                $root,
                ['AI6_RUNTIME_PROFILE'],
                ['AI6_RUNTIME_PROFILE' => 'codex-cli-v1'],
                new RedactionContext('project-1', 'run-1', 'agent-environment'),
                policy: ProcessPolicyName::AGENT,
                resultDirectory: $root.'/storage/framework/testing',
                artifactDirectory: $root.'/storage/framework/testing',
            ));
            self::assertTrue($result->succeeded(), $result->errorOutput);
            self::assertSame(array_fill(0, count($forbidden), 'missing'), json_decode($result->output, true, 8, JSON_THROW_ON_ERROR));
        } finally {
            foreach ($forbidden as $name) {
                putenv($name);
            }
        }
    }

    private function redactor(): Redactor
    {
        return new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator(new RedactionKeyring('test-v1', ['test-v1' => ['version' => 1, 'key' => str_repeat('k', 32)]])),
        );
    }
}
