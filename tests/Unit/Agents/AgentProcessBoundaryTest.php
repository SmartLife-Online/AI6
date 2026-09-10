<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CodexCliConfiguration;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Doctor\CodexCliDoctorCheck;
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
        self::assertSame(
            [PHP_BINARY, config('ai6.codex.binary')],
            $configured->allowedExecutables,
            'The shipped agent policy names exactly the FakeAgent executable and the pinned Codex binary (AI6-033).',
        );
        self::assertContains('CODEX_HOME', $configured->environmentAllowlist);
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

    /**
     * TC-03: an empty AI6_CODEX_BINARY is the documented "Codex not set up"
     * state. It locks the codex_cli profiles by name and must leave the shared
     * agent policy and the FakeAgent untouched — an empty entry in
     * allowed_executables would make the whole policy unreadable instead.
     */
    public function test_an_empty_codex_binary_locks_only_codex_and_keeps_the_shared_agent_policy_usable(): void
    {
        $previous = getenv('AI6_CODEX_BINARY');
        putenv('AI6_CODEX_BINARY=');
        try {
            $reloaded = require base_path('config/ai6.php');
            config([
                'ai6.process.policies.agent' => $reloaded['process']['policies']['agent'],
                'ai6.codex' => $reloaded['codex'],
            ]);
            $policy = ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::AGENT);
            self::assertSame([PHP_BINARY], $policy->allowedExecutables, 'Only Codex is locked; the FakeAgent executable stays allowed.');
            self::assertFalse(CodexCliConfiguration::fromConfiguredValues()->binaryPresent());

            $doctor = new CodexCliDoctorCheck(
                $this->app->make(AgentProfileRegistry::class),
                $this->app->make(ProviderRuntimeProfileRegistry::class),
                $this->app->make(ControlProcessRunner::class),
            );
            $result = $doctor->run();
            self::assertTrue($result->passed);
            self::assertSame('nicht eingerichtet; Profile von codex_cli gesperrt', $result->details['Zustand']);

            $fake = $this->app->makeWith(AgentAdapter::class, ['providerAlias' => 'fake']);
            self::assertInstanceOf(FakeAgentAdapter::class, $fake);
            self::assertJson($fake->result(new AgentResultContext(
                AgentRole::IMPLEMENTATION,
                new PromptSnapshot('1', [], ['implementation' => 'Auftrag.'], str_repeat('a', 64)),
                new InstructionSnapshot('fake', [], str_repeat('b', 64)),
                $this->app->make(ProviderRuntimeProfileRegistry::class)->get('fake-v1'),
                ['AC-01'],
                '',
                slotId: 'slot-1',
            )));

            // The guard is load-bearing: an empty entry would take the policy down.
            config(['ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, '']]);
            $this->expectException(ConfigurationException::class);
            ProcessPolicyRegistry::fromConfiguredValues();
        } finally {
            $previous === false ? putenv('AI6_CODEX_BINARY') : putenv('AI6_CODEX_BINARY='.$previous);
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
