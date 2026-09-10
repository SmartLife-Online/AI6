<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultStatus;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CodexCliAdapter;
use App\AI6\Agents\CodexCliConfiguration;
use App\AI6\Agents\CredentialProjection;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\InstructionDiscovery;
use App\AI6\Agents\InstructionResolutionProfile;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Agents\TurnContainmentBoundary;
use App\AI6\Git\CanonicalJson;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Tests\TestCase;

/**
 * TC-14: one real Codex turn against the pinned CLI. Skipped without the
 * explicit flag; with the flag every missing prerequisite is a failure.
 * The turn proves transport, answer contract and home discipline at the
 * real binary — including the two turn-free capability probes the adapter
 * runs first, which fail the turn if the pinned binary reports a different
 * feature surface. The isolation of the agent role stays MG-01.
 */
final class CodexCliSmokeTest extends TestCase
{
    public function test_a_real_codex_review_turn_passes_the_central_validator(): void
    {
        if (getenv('AI6_RUN_CODEX_SMOKE') !== '1') {
            self::markTestSkipped('Der reale Codex-Smoke läuft nur mit AI6_RUN_CODEX_SMOKE=1, AI6_CODEX_BINARY, AI6_CODEX_PINNED_VERSION, AI6_CODEX_SANDBOX_PROOF und AI6_CODEX_SMOKE_AUTH_FILE.');
        }
        $binary = (string) (getenv('AI6_CODEX_BINARY') ?: config('ai6.codex.binary'));
        $pin = (string) (getenv('AI6_CODEX_PINNED_VERSION') ?: config('ai6.codex.pinned_version'));
        $authFile = (string) getenv('AI6_CODEX_SMOKE_AUTH_FILE');
        $proof = (string) (getenv('AI6_CODEX_SANDBOX_PROOF') ?: config('ai6.codex.sandbox_proof'));
        $configuration = new CodexCliConfiguration($binary, $pin, $proof);
        if (! $configuration->binaryPresent()) {
            self::fail('AI6_CODEX_BINARY benennt kein vorhandenes ausführbares Codex-Binary.');
        }
        if ($pin === '' || ! in_array($pin, CodexCliAdapter::VERIFIED_TRANSPORT_VERSIONS, true)) {
            self::fail('AI6_CODEX_PINNED_VERSION fehlt oder ist keine transportverifizierte Version.');
        }
        if ($authFile === '' || ! is_file($authFile) || is_link($authFile)) {
            self::fail('AI6_CODEX_SMOKE_AUTH_FILE benennt keine ausdrücklich für den Smoke bereitgestellte Testauthprojektion.');
        }
        if (! $configuration->sandboxProofBinds()) {
            self::fail('AI6_CODEX_SANDBOX_PROOF fehlt oder bindet nicht an diesen Pin und diese Plattform ('
                .$pin.':'.CodexCliConfiguration::runtimePlatform().'); der Nachweis stammt aus AI6-033/MG-01.');
        }

        $root = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/ai6-codex-smoke-'.bin2hex(random_bytes(6));
        foreach (['export/app', 'inputs', 'outputs'] as $directory) {
            self::assertTrue(mkdir($root.'/'.$directory, 0700, true));
        }
        file_put_contents($root.'/export/app/Example.php', "<?php\n\nfunction example(): int\n{\n    return 1;\n}\n");
        $instructions = "# AGENTS.md\n\nDies ist die gebundene Instruktionsdatei des Smoke-Tests. Antworte auf Deutsch.\n";
        $entry = new InstructionSnapshotEntry('agents_md', 'repository', 10, 'AGENTS.md', str_repeat('a', 40), $instructions, []);
        $snapshot = new InstructionSnapshot('codex_cli', [$entry], str_repeat('b', 64));
        $runtime = $this->app->make(ProviderRuntimeProfileRegistry::class)->get('codex-cli-v1');
        $limits = $this->app->make(AgentInputLimits::class);
        $manager = new ExecutionHomeManager(new CanonicalJson, new CredentialRevisionRegistry(['codex_cli' => 'smoke-1']),
            $this->app->make(ProviderRuntimeProfileRegistry::class), null, $limits);
        $redaction = new RedactionContext('smoke', null, 'codex-smoke');
        $prompt = $this->app->make(PromptRenderer::class)->snapshot([
            new PromptRenderRequest('quality_review', new PromptVariables(['context' => json_encode([
                'ticket' => 'AI6-033-SMOKE', 'criterion_refs' => ['AC-01'],
                'acceptance_criteria' => ['AC-01' => 'app/Example.php enthält eine Funktion example(), die 1 zurückgibt.'],
                'reviewed_paths' => ['app/Example.php'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]), 'functional_correctness'),
        ], $redaction);
        $context = new AgentResultContext(AgentRole::QUALITY_REVIEW, $prompt, $snapshot, $runtime, ['AC-01'], '', slotId: 'smoke-slot',
            model: (string) (getenv('AI6_CODEX_SMOKE_MODEL') ?: 'gpt-5.3-codex'), effort: (string) (getenv('AI6_CODEX_SMOKE_EFFORT') ?: 'low'));
        $home = $manager->create($root.'/inputs', $root.'/outputs', 'smoke-slot', 'smoke-session', $root.'/export',
            new InstructionResolutionProfile('codex_cli', ['agents_md' => new InstructionDiscovery('agents_md', 10, 'repository')]),
            $snapshot, $runtime, new CredentialProjection('codex_cli', 'smoke-1', ['auth.json' => $authFile]), turnContext: $context);
        $before = $this->tree($home->root);

        config([
            'ai6.process.policies.agent.allowed_executables' => [PHP_BINARY, $binary],
            'ai6.process.policies.agent.working_roots' => [$root],
        ]);
        if (DIRECTORY_SEPARATOR !== '/') {
            config(['ai6.process.policies.agent.requires_process_group' => false]);
        }
        $policies = ProcessPolicyRegistry::fromConfiguredValues();
        $redactor = $this->app->make(Redactor::class);
        $adapter = new CodexCliAdapter($configuration, $limits, $redactor, new RestrictedJsonDecoder($redactor, $policies),
            new ControlProcessRunner($this->app->make(ProcessConfiguration::class), $redactor, $this->app->make(EffectLock::class), $policies, new TurnContainmentBoundary));
        try {
            $answer = $adapter->turn($context, $home, static function (): void {});
            $validated = $this->app->make(AgentResultValidator::class)->validate($answer->bytes, $context, $redaction);
            self::assertContains($validated->status, AgentResultStatus::allowedFor(AgentRole::QUALITY_REVIEW));
            self::assertSame(['AC-01'], array_map(static fn ($coverage) => $coverage->criterionId, $validated->criterionCoverage));
            self::assertSame(CodexCliAdapter::USAGE_SOURCE, $answer->usageSource, 'The real CLI reports its usage in turn.completed.');
            self::assertSame($before, $this->tree($home->root), 'The sealed home is byte-identical after the real turn.');
            self::assertSame('exec', $adapter->lastCommand[1]);
            self::assertSame(['--', '-'], array_slice($adapter->lastCommand, -2), 'The real CLI received the prompt over standard input.');
            self::assertNotSame('', $adapter->lastPrompt);
            self::assertSame(['auth.json'], array_values(array_diff(scandir($home->authDirectory) ?: [], ['.', '..'])),
                'The real CLI materialized no bundled extension in the read-only projection.');
        } finally {
            $manager->destroy($home);
            $this->remove($root);
        }
    }

    /** @return array<string, string> */
    private function tree(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1))] = (string) hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            @chmod($entry->getPathname(), 0700);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
