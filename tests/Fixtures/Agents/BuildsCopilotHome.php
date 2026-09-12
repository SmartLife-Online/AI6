<?php

namespace Tests\Fixtures\Agents;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CredentialProjection;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\InstructionProfileRegistry;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Agents\TurnContainmentBoundary;
use App\AI6\Git\CanonicalJson;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\Redactor;

trait BuildsCopilotHome
{
    private string $root;

    private string $wrappers;

    /** @var list<ExecutionHome> */
    private array $copilotHomes = [];

    private function createCopilotFixture(): void
    {
        $this->root = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/ai6-copilot-'.bin2hex(random_bytes(6));
        $this->wrappers = str_replace('\\', '/', storage_path('framework/testing/copilot-'.bin2hex(random_bytes(6))));
        foreach (['export', 'inputs', 'outputs'] as $directory) {
            self::assertTrue(mkdir($this->root.'/'.$directory, 0700, true));
        }
        file_put_contents($this->root.'/export/example.txt', 'Original');
        file_put_contents(implode(DIRECTORY_SEPARATOR, [$this->root, 'token']), 'test-projection');
    }

    private function copilotContext(string $prompt = 'Prüfe das Beispiel.', AgentRole $role = AgentRole::QUALITY_REVIEW): AgentResultContext
    {
        $snapshot = new InstructionSnapshot('github_copilot_cli', [new InstructionSnapshotEntry('agents_md', 'repository', 10, 'AGENTS.md', str_repeat('a', 40), 'Bound instructions.', [])], str_repeat('b', 64));

        return new AgentResultContext($role, new PromptSnapshot('1', [], [$role->value => $prompt], str_repeat('c', 64)),
            $snapshot, app(ProviderRuntimeProfileRegistry::class)->get('github-copilot-cli-v1'), ['AC-01'], '', slotId: 'slot-1', model: 'gpt-5.4', effort: 'provider_default');
    }

    private function copilotManager(): ExecutionHomeManager
    {
        return new ExecutionHomeManager(app(CanonicalJson::class), new CredentialRevisionRegistry(['github_copilot_cli' => 'test-v1']), app(ProviderRuntimeProfileRegistry::class), app(InstructionProfileRegistry::class), app(AgentInputLimits::class));
    }

    private function copilotHome(AgentResultContext $context): ExecutionHome
    {
        $home = $this->copilotManager()->create($this->root.'/inputs', $this->root.'/outputs', $context->slotId, 'session', $this->root.'/export',
            app(InstructionProfileRegistry::class)->get('github_copilot_cli'), $context->instructionSnapshot, $context->runtimeProfile,
            new CredentialProjection('github_copilot_cli', 'test-v1', ['token' => implode(DIRECTORY_SEPARATOR, [$this->root, 'token'])]), turnContext: $context);
        $this->copilotHomes[] = $home;

        return $home;
    }

    private function copilotAdapter(string $scenario = 'success', bool $evidence = true, ?AgentInputLimits $limits = null, ?string $binary = null): GitHubCopilotCliAdapter
    {
        $binary ??= FakeCopilotBinary::create($this->wrappers, $scenario);
        config(['ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, $binary], 'ai6.process.policies.agent.working_roots' => [$this->root]]);
        if (DIRECTORY_SEPARATOR !== '/') {
            config(['ai6.process.policies.agent.requires_process_group' => false]);
        }
        $configuration = new GitHubCopilotCliConfiguration($binary, '1.0.83');
        $context = $this->copilotContext();
        $configuration = new GitHubCopilotCliConfiguration($binary, '1.0.83', $evidence ? [$configuration->evidenceKey($context->runtimeProfile, $context->role, $context->model, $context->effort)] : []);
        $policies = ProcessPolicyRegistry::fromConfiguredValues();
        $redactor = app(Redactor::class);
        $runner = new ControlProcessRunner(app(ProcessConfiguration::class), $redactor, app(EffectLock::class), $policies, new TurnContainmentBoundary);

        return new GitHubCopilotCliAdapter($configuration, $limits ?? app(AgentInputLimits::class), $redactor, new RestrictedJsonDecoder($redactor, $policies), app(AgentProfileRegistry::class), app(CanonicalJson::class), $runner);
    }

    private function destroyCopilotFixture(): void
    {
        foreach ($this->copilotHomes as $home) {
            $this->copilotManager()->destroy($home);
        }
        foreach ([$this->root, $this->wrappers] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) {
                chmod($entry->getPathname(), 0700);
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }
}
