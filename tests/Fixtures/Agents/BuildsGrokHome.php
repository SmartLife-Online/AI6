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
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\GrokCliConfiguration;
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

trait BuildsGrokHome
{
    private string $root;

    private string $wrappers;

    /** @var list<ExecutionHome> */
    private array $grokHomes = [];

    private string $selectedGrokModel = 'provider_default';

    private function createGrokFixture(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Grok session links require the Linux runtime.');
        }
        $this->root = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/ai6-grok-'.bin2hex(random_bytes(6));
        $this->wrappers = str_replace('\\', '/', storage_path('framework/testing/grok-'.bin2hex(random_bytes(6))));
        foreach (['export', 'inputs', 'outputs'] as $directory) {
            self::assertTrue(mkdir($this->root.'/'.$directory, 0700, true));
        }
        file_put_contents($this->root.'/export/example.txt', 'Original');
        file_put_contents(implode(DIRECTORY_SEPARATOR, [$this->root, 'token']), 'test-projection');
    }

    protected function grokModel(): string
    {
        return $this->selectedGrokModel;
    }

    private function grokContext(string $prompt = 'Prüfe das Beispiel.', AgentRole $role = AgentRole::QUALITY_REVIEW): AgentResultContext
    {
        $snapshot = new InstructionSnapshot('grok_cli', [new InstructionSnapshotEntry('agents_md', 'repository', 10, 'AGENTS.md', str_repeat('a', 40), 'Bound instructions.', [])], str_repeat('b', 64));

        return new AgentResultContext($role, new PromptSnapshot('1', [], [$role->value => $prompt], str_repeat('c', 64)),
            $snapshot, app(ProviderRuntimeProfileRegistry::class)->get('grok-cli-v1'), ['AC-01'], '', slotId: 'slot-1',
            expectedFindingIds: $role === AgentRole::FINDING_VERIFICATION ? ['finding-1'] : [], model: $this->grokModel(), effort: 'provider_default');
    }

    private function grokManager(): ExecutionHomeManager
    {
        return new ExecutionHomeManager(app(CanonicalJson::class), new CredentialRevisionRegistry(['grok_cli' => 'test-v1']), app(ProviderRuntimeProfileRegistry::class), app(InstructionProfileRegistry::class), app(AgentInputLimits::class));
    }

    private function grokHome(AgentResultContext $context): ExecutionHome
    {
        $role = config('ai6.runtime_role');
        config(['ai6.runtime_role' => 'agent']);
        try {
            $home = $this->grokManager()->create($this->root.'/inputs', $this->root.'/outputs', $context->slotId, 'session', $this->root.'/export',
                app(InstructionProfileRegistry::class)->get('grok_cli'), $context->instructionSnapshot, $context->runtimeProfile,
                new CredentialProjection('grok_cli', 'test-v1', ['token' => implode(DIRECTORY_SEPARATOR, [$this->root, 'token'])]), turnContext: $context);
        } finally {
            config(['ai6.runtime_role' => $role]);
        }
        $this->grokHomes[] = $home;

        return $home;
    }

    protected function grokAdapter(string $scenario = 'success', bool $evidence = true, ?AgentInputLimits $limits = null, ?string $binary = null): GrokCliAdapter
    {
        $binary ??= FakeGrokBinary::create($this->wrappers, $scenario);
        config(['ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, $binary], 'ai6.process.policies.agent.working_roots' => [$this->root]]);
        if (DIRECTORY_SEPARATOR !== '/') {
            config(['ai6.process.policies.agent.requires_process_group' => false]);
        }
        $configuration = new GrokCliConfiguration($binary, '1.0.5');
        $context = $this->grokContext();
        $configuration = new GrokCliConfiguration($binary, '1.0.5', $evidence ? array_map(fn (AgentRole $role): string => $configuration->evidenceKey($context->runtimeProfile, $role, $context->model, $context->effort), [AgentRole::QUALITY_REVIEW, AgentRole::FINDING_VERIFICATION]) : []);
        $policies = ProcessPolicyRegistry::fromConfiguredValues();
        $redactor = app(Redactor::class);
        $runner = new ControlProcessRunner(app(ProcessConfiguration::class), $redactor, app(EffectLock::class), $policies, new TurnContainmentBoundary);

        return new GrokCliAdapter($configuration, $limits ?? app(AgentInputLimits::class), $redactor, new RestrictedJsonDecoder($redactor, $policies), app(AgentProfileRegistry::class), $runner);
    }

    private function destroyGrokFixture(): void
    {
        if (! isset($this->root)) {
            return;
        }
        foreach ($this->grokHomes as $home) {
            $this->grokManager()->destroy($home);
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
