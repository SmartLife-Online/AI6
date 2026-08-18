<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultImporter;
use App\AI6\Agents\AgentResultValidationError;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\InstructionPatchException;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\TestCase;

final class AgentResultBoundaryTest extends TestCase
{
    public function test_fake_scenario_golden_vectors_are_deterministic_and_use_the_shared_adapter_contract(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Agents/fake-agent-v1.json'), true, 16, JSON_THROW_ON_ERROR);

        self::assertCount(count(AgentScenario::cases()), $fixture);

        foreach ($fixture as $vector) {
            $scenario = AgentScenario::from($vector['scenario']);
            $context = $this->context(AgentRole::from($vector['role']), $vector['actual_diff'] ?? '');
            $adapter = new FakeAgentAdapter($scenario);
            self::assertInstanceOf(AgentAdapter::class, $adapter);
            $first = $adapter->result($context);
            self::assertSame($first, $adapter->result($context));
            // The golden vector pins every byte; only the catalog-derived prompt hash is substituted.
            self::assertSame(
                str_replace('%PROMPT_SNAPSHOT_HASH%', $context->promptSnapshot->hash, $vector['bytes']),
                $first,
                $vector['scenario'],
            );
            if (array_key_exists('error', $vector)) {
                $this->assertVectorError($first, $context, $vector['error']);

                continue;
            }

            self::assertSame($vector['status'], $this->app->make(AgentResultImporter::class)->validate($first, $context, $this->redactionContext())->status->value);
        }
    }

    public function test_invalid_provider_bytes_leave_no_side_effect_and_never_echo_provider_text(): void
    {
        $patchDirectory = sys_get_temp_dir().'/ai6-agent-result-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($patchDirectory));
        $home = new ExecutionHome('', '', '', '', '', '', '', '', '', $patchDirectory);
        $before = scandir($patchDirectory);
        $providerText = 'provider-secret-cleartext';

        try {
            $this->app->make(AgentResultImporter::class)->importInstructionPatch(
                '{"summary":"'.$providerText.'"',
                $this->context(AgentRole::IMPLEMENTATION),
                $this->redactionContext(),
                $home,
            );
            self::fail('Malformed provider output was accepted.');
        } catch (JsonDecodingException $exception) {
            self::assertStringNotContainsString($providerText, $exception->getMessage());
        }

        self::assertSame($before, scandir($patchDirectory));
        rmdir($patchDirectory);
    }

    public function test_valid_instruction_patch_is_imported_once_through_the_existing_channel(): void
    {
        $patchDirectory = sys_get_temp_dir().'/ai6-agent-patch-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($patchDirectory));
        $home = new ExecutionHome('', '', '', '', '', '', '', '', '', $patchDirectory);
        $context = $this->context(AgentRole::IMPLEMENTATION, '', true);
        $document = [
            'schema_version' => 'ai6.agent.v1',
            'status' => 'completed',
            'summary' => 'Patch import.',
            'prompt_snapshot_hash' => $context->promptSnapshot->hash,
            'instruction_snapshot_hash' => $context->instructionSnapshot->hash,
            'provider_runtime_profile_hash' => $context->runtimeProfile->hash,
            'decisions' => [['key' => 'd1', 'title' => 'Patch', 'rationale' => 'Strukturierter Kanal.']],
            'changed_paths' => [],
            'open_manual_gates' => [],
            'implementation_summary' => [
                'changed_components' => [],
                'decisions' => [],
                'assumptions' => [],
                'deviations' => [],
                'known_limits' => [],
                'tests' => [],
                'review_focus' => [],
            ],
            'instruction_patch' => [
                'schema_version' => 'ai6.instruction-patch.v1',
                'path' => 'instructions/agent.md',
                'expected_blob_sha' => str_repeat('a', 40),
                'format' => 'utf8_file_replacement_v1',
                'content_base64' => base64_encode('hello'),
                'content_length' => 5,
                'content_sha256' => hash('sha256', 'hello'),
            ],
        ];
        $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $importer = $this->app->make(AgentResultImporter::class);
        self::assertSame('hello', $importer->importInstructionPatch($bytes, $context, $this->redactionContext(), $home)->instructionPatch?->content);
        self::assertFileExists($patchDirectory.'/proposal.json');
        try {
            $importer->importInstructionPatch($bytes, $context, $this->redactionContext(), $home);
            self::fail('A second patch proposal was accepted.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('Exactly one instruction patch may be proposed.', $exception->getMessage());
        }
        unlink($patchDirectory.'/proposal.json');
        rmdir($patchDirectory);
    }

    public function test_new_instruction_path_requires_explicit_server_approval(): void
    {
        $patchDirectory = sys_get_temp_dir().'/ai6-agent-new-patch-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($patchDirectory));
        $home = new ExecutionHome('', '', '', '', '', '', '', '', '', $patchDirectory);
        $context = $this->context(AgentRole::IMPLEMENTATION, '', true, true);
        $document = [
            'schema_version' => 'ai6.agent.v1', 'status' => 'completed', 'summary' => 'Neue Datei.',
            'prompt_snapshot_hash' => $context->promptSnapshot->hash,
            'instruction_snapshot_hash' => $context->instructionSnapshot->hash,
            'provider_runtime_profile_hash' => $context->runtimeProfile->hash,
            'decisions' => [['key' => 'd1', 'title' => 'Patch', 'rationale' => 'Strukturierter Kanal.']],
            'changed_paths' => [],
            'open_manual_gates' => [],
            'implementation_summary' => [
                'changed_components' => [],
                'decisions' => [],
                'assumptions' => [],
                'deviations' => [],
                'known_limits' => [],
                'tests' => [],
                'review_focus' => [],
            ],
            'instruction_patch' => [
                'schema_version' => 'ai6.instruction-patch.v1', 'path' => 'instructions/new.md',
                'expected_blob_sha' => null, 'format' => 'utf8_file_replacement_v1',
                'content_base64' => base64_encode('new'), 'content_length' => 3,
                'content_sha256' => hash('sha256', 'new'),
            ],
        ];
        $bytes = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $importer = $this->app->make(AgentResultImporter::class);
        $importer->importInstructionPatch($bytes, $context, $this->redactionContext(), $home);
        self::assertFileExists($patchDirectory.'/proposal.json');
        unlink($patchDirectory.'/proposal.json');
        rmdir($patchDirectory);
    }

    private function assertVectorError(string $bytes, AgentResultContext $context, string $error): void
    {
        try {
            $this->app->make(AgentResultImporter::class)->validate($bytes, $context, $this->redactionContext());
            self::fail('The invalid fake scenario was accepted.');
        } catch (JsonDecodingException $exception) {
            self::assertSame($error, $exception->reason->value);
        } catch (AgentResultValidationException $exception) {
            self::assertSame(AgentResultValidationError::from($error), $exception->reason);
        }
    }

    private function context(AgentRole $role, string $actualDiff = '', bool $instructionUpdate = false, bool $newInstructionPath = false): AgentResultContext
    {
        $prompt = $this->app->make(PromptRenderer::class)->snapshot([
            new PromptRenderRequest('implementation', new PromptVariables(['context' => 'Test'])),
        ], $this->redactionContext());
        $entry = new InstructionSnapshotEntry('agent', 'repository', 1, $newInstructionPath ? 'instructions/base.md' : 'instructions/agent.md', str_repeat('a', 40), 'Anweisung', []);
        $targetPath = $newInstructionPath ? 'instructions/new.md' : 'instructions/agent.md';

        return new AgentResultContext(
            $role,
            $prompt,
            new InstructionSnapshot('fake', [$entry], str_repeat('b', 64)),
            new ProviderRuntimeProfile('fake-v1', 1, [], [], [], str_repeat('c', 64)),
            ['AC-01', 'AC-02'],
            $actualDiff,
            $instructionUpdate,
            [$targetPath],
            [$targetPath => $newInstructionPath ? null : str_repeat('a', 40)],
        );
    }

    private function redactionContext(): RedactionContext
    {
        return new RedactionContext('project-test', 'run-test', 'agent-result-feature');
    }
}
