<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentResultStatus;
use App\AI6\Agents\AgentResultValidationError;
use App\AI6\Agents\AgentResultValidationException;
use App\AI6\Agents\AgentResultValidator;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Shared\Json\JsonDecodingError;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Redaction\RedactionContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

final class AgentResultValidatorTest extends TestCase
{
    public function test_it_accepts_complete_role_specific_results_and_the_deterministic_fake(): void
    {
        $validator = $this->app->make(AgentResultValidator::class);
        $contexts = [
            [AgentRole::IMPLEMENTATION, AgentScenario::SUCCESS],
            [AgentRole::QUALITY_REVIEW, AgentScenario::FINDINGS],
            [AgentRole::FINDING_VERIFICATION, AgentScenario::SUCCESS],
            [AgentRole::SECURITY_REVIEW, AgentScenario::SECURITY_FINDINGS],
        ];

        foreach ($contexts as [$role, $scenario]) {
            $context = $this->context($role);
            $fake = new FakeAgentAdapter($scenario);
            $first = $fake->result($context);
            self::assertSame($first, $fake->result($context));
            self::assertNotSame('', $validator->validate($first, $context, $this->redactionContext())->summary);
        }
    }

    public function test_it_rejects_schema_binding_and_criterion_failures_before_a_result_exists(): void
    {
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        $document = $this->document($context, AgentResultStatus::NOTHING_TO_FIX);
        $document['criterion_coverage'] = ['AC-99'];
        $this->assertValidation($document, $context, AgentResultValidationError::CRITERION_REFERENCE);

        $document = $this->document($context, AgentResultStatus::NOTHING_TO_FIX);
        $document['prompt_snapshot_hash'] = str_repeat('0', 64);
        $document['criterion_coverage'] = ['AC-01', 'AC-02'];
        $this->assertValidation($document, $context, AgentResultValidationError::BINDING);

        try {
            $this->app->make(AgentResultValidator::class)->validate('{"schema_version":"ai6.quality-review.v1","schema_version":"ai6.quality-review.v1"}', $context, $this->redactionContext());
            self::fail('A duplicate schema key was accepted.');
        } catch (JsonDecodingException $exception) {
            self::assertSame(JsonDecodingError::DUPLICATE_KEY, $exception->reason);
        }
    }

    public function test_no_change_with_a_real_diff_and_invalid_patch_data_have_no_importable_result(): void
    {
        $context = $this->context(AgentRole::IMPLEMENTATION, 'actual change');
        $this->assertValidation($this->document($context, AgentResultStatus::NO_CHANGE_REQUIRED), $context, AgentResultValidationError::NO_CHANGE_DIFF);

        $context = $this->context(AgentRole::IMPLEMENTATION, '', true);
        $document = $this->document($context, AgentResultStatus::COMPLETED);
        $document['instruction_patch'] = [
            'schema_version' => 'ai6.instruction-patch.v1',
            'path' => 'instructions/agent.md',
            'expected_blob_sha' => str_repeat('a', 40),
            'format' => 'utf8_file_replacement_v1',
            'content_base64' => 'aGVsbG8=',
            'content_length' => 5,
            'content_sha256' => hash('sha256', 'hello'),
        ];
        self::assertSame('hello', $this->app->make(AgentResultValidator::class)
            ->validate(json_encode($document, JSON_THROW_ON_ERROR), $context, $this->redactionContext())
            ->instructionPatch?->content);

        $document['instruction_patch']['content_base64'] = "aGVsbG8=\n";
        $this->assertValidation($document, $context, AgentResultValidationError::INSTRUCTION_PATCH);
    }

    public function test_mutation_metadata_is_rejected_at_the_result_boundary(): void
    {
        $context = $this->context(AgentRole::IMPLEMENTATION);
        foreach (['ticket_status', 'approval_id', 'run_id', 'workflow_step'] as $field) {
            $document = $this->document($context, AgentResultStatus::COMPLETED);
            $document[$field] = 'done';
            $this->assertValidation($document, $context, AgentResultValidationError::SCHEMA);
        }
    }

    public function test_the_agents_module_owns_no_write_path_into_the_ticket_files(): void
    {
        $root = dirname(__DIR__, 3).'/app/AI6/Agents';
        $offenders = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $files = 0;

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files++;
            $contents = (string) file_get_contents($file->getPathname());
            // The module may never name the ticket directory: neither as a read nor as a write target.
            if (preg_match('#[\'"/]tickets/#', $contents) === 1) {
                $offenders[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        self::assertGreaterThan(10, $files);
        self::assertSame([], $offenders);
    }

    public function test_all_result_bindings_are_required_and_role_statuses_are_closed(): void
    {
        $context = $this->context(AgentRole::QUALITY_REVIEW);
        foreach (['instruction_snapshot_hash', 'provider_runtime_profile_hash'] as $binding) {
            $document = $this->document($context, AgentResultStatus::NOTHING_TO_FIX);
            unset($document[$binding]);
            $this->assertValidation($document, $context, AgentResultValidationError::BINDING);
        }
        foreach (['instruction_snapshot_hash', 'provider_runtime_profile_hash'] as $binding) {
            $document = $this->document($context, AgentResultStatus::NOTHING_TO_FIX);
            $document[$binding] = str_repeat('0', 64);
            $this->assertValidation($document, $context, AgentResultValidationError::BINDING);
        }

        $document = $this->document($context, AgentResultStatus::NOTHING_TO_FIX);
        $document['unexpected'] = true;
        $this->assertValidation($document, $context, AgentResultValidationError::SCHEMA);

        $implementation = $this->context(AgentRole::IMPLEMENTATION);
        $this->assertValidation($this->document($implementation, AgentResultStatus::FINDINGS_TO_FIX), $implementation, AgentResultValidationError::STATUS);

        $wrongVersion = $this->document($context, AgentResultStatus::NOTHING_TO_FIX);
        $wrongVersion['schema_version'] = 'ai6.agent.v1';
        $this->assertValidation($wrongVersion, $context, AgentResultValidationError::SCHEMA);
    }

    public function test_human_request_shape_and_recommendation_are_restricted(): void
    {
        $context = $this->context(AgentRole::IMPLEMENTATION);
        $base = $this->document($context, AgentResultStatus::NEEDS_HUMAN);
        $base['human_request'] = null;
        self::assertNull($this->app->make(AgentResultValidator::class)->validate(json_encode($base, JSON_THROW_ON_ERROR), $context, $this->redactionContext())->humanRequest);

        foreach ([
            ['human_request' => ['wrong']],
            ['human_request' => ['kind' => 'clarification']],
            ['human_request' => ['kind' => 'clarification', 'title' => 'T', 'message' => 'M', 'why_needed' => 'W', 'response_mode' => 'select', 'options' => [['key' => 'a', 'label' => 'A']], 'recommended_option' => 'missing', 'affected_paths' => [], 'criterion_refs' => []]],
            ['human_request' => ['kind' => 'clarification', 'title' => 'T', 'message' => 'M', 'why_needed' => 'W', 'response_mode' => 'select', 'options' => [['key' => 'a', 'label' => 'A']], 'recommended_option' => 'a', 'affected_paths' => [], 'criterion_refs' => [], 'role' => 'implementation']],
            ['human_request' => ['kind' => 'clarification', 'title' => 'T', 'message' => 'M', 'why_needed' => 'W', 'response_mode' => 'select', 'options' => [['key' => 'a', 'label' => 'A']], 'recommended_option' => 'a', 'affected_paths' => [], 'criterion_refs' => [], 'step_up' => true]],
        ] as $mutation) {
            $document = $this->document($context, AgentResultStatus::NEEDS_HUMAN);
            $document['human_request'] = $mutation['human_request'];
            $this->assertValidation($document, $context, AgentResultValidationError::SCHEMA);
        }
    }

    public function test_instruction_patch_negative_matrix_imports_no_byte(): void
    {
        $limit = $this->app->make(AgentInputLimits::class)->maxInstructionFileBytes;
        $existing = $this->context(AgentRole::IMPLEMENTATION, '', true);
        $closed = $this->context(AgentRole::IMPLEMENTATION, '', false);
        $absent = $this->context(AgentRole::IMPLEMENTATION, '', true, 'instructions/new.md', null);
        $bom = "\xEF\xBB\xBF".'hello';
        $invalidUtf8 = "\xC3\x28";
        $oversized = str_repeat('x', $limit + 1);

        $cases = [
            // Modus nicht freigegeben
            'closed mode' => [$closed, $this->patchDocument(), AgentResultValidationError::INSTRUCTION_PATCH],
            // mehrere Ziele
            'multiple targets' => [$existing, [$this->patchDocument(), $this->patchDocument()], AgentResultValidationError::INSTRUCTION_PATCH],
            // falscher alter Blob
            'wrong blob' => [$existing, $this->patchDocument(['expected_blob_sha' => str_repeat('b', 40)]), AgentResultValidationError::INSTRUCTION_PATCH],
            // null trotz vorhandener Datei
            'illegal null blob' => [$existing, $this->patchDocument(['expected_blob_sha' => null]), AgentResultValidationError::INSTRUCTION_PATCH],
            // Blobbindung trotz nachgewiesener Abwesenheit
            'blob despite proven absence' => [$absent, $this->patchDocument(['path' => 'instructions/new.md']), AgentResultValidationError::INSTRUCTION_PATCH],
            // falsche Schemaversion des Patchobjekts
            'wrong patch schema' => [$existing, $this->patchDocument(['schema_version' => 'ai6.instruction-patch.v2']), AgentResultValidationError::INSTRUCTION_PATCH],
            // unzulässiges Format
            'wrong format' => [$existing, $this->patchDocument(['format' => 'diff_v3']), AgentResultValidationError::INSTRUCTION_PATCH],
            // nicht kanonisches Base64
            'non canonical base64' => [$existing, $this->patchDocument(['content_base64' => "aGVsbG8=\n"]), AgentResultValidationError::INSTRUCTION_PATCH],
            // BOM
            'bom' => [$existing, $this->patchDocument(content: $bom), AgentResultValidationError::INSTRUCTION_PATCH],
            // ungültiges UTF-8
            'invalid utf8' => [$existing, $this->patchDocument(content: $invalidUtf8), AgentResultValidationError::INSTRUCTION_PATCH],
            // Redaktionstreffer im Inhalt
            'redacted content' => [$existing, $this->patchDocument(content: 'api_key: sk-live-0123456789'), AgentResultValidationError::REDACTED_INSTRUCTION_PATCH],
            // abweichende Länge
            'wrong length' => [$existing, $this->patchDocument(['content_length' => 6]), AgentResultValidationError::INSTRUCTION_PATCH],
            // abweichender Hash
            'wrong hash' => [$existing, $this->patchDocument(['content_sha256' => str_repeat('0', 64)]), AgentResultValidationError::INSTRUCTION_PATCH],
            // Pfad außerhalb des freigegebenen Scopes
            'outside scope' => [$existing, $this->patchDocument(['path' => 'instructions/other.md']), AgentResultValidationError::INSTRUCTION_PATCH],
            // Pfadtraversal beziehungsweise nicht kanonischer Pfad
            'traversal' => [$existing, $this->patchDocument(['path' => 'instructions/../agent.md']), AgentResultValidationError::SCHEMA],
            // absoluter Pfad
            'absolute path' => [$existing, $this->patchDocument(['path' => '/etc/agent.md']), AgentResultValidationError::SCHEMA],
            // Instruktions-Einzelbytelimit überschritten
            'over the file limit' => [$existing, $this->patchDocument(content: $oversized), AgentResultValidationError::INSTRUCTION_PATCH],
        ];

        foreach ($cases as $label => [$context, $patch, $reason]) {
            $document = $this->document($context, AgentResultStatus::COMPLETED);
            $document['instruction_patch'] = $patch;
            $this->assertValidation($document, $context, $reason, $label);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function patchDocument(array $overrides = [], string $content = 'hello'): array
    {
        return array_replace([
            'schema_version' => 'ai6.instruction-patch.v1',
            'path' => 'instructions/agent.md',
            'expected_blob_sha' => str_repeat('a', 40),
            'format' => 'utf8_file_replacement_v1',
            'content_base64' => base64_encode($content),
            'content_length' => strlen($content),
            'content_sha256' => hash('sha256', $content),
        ], $overrides);
    }

    /** @param array<string, mixed> $document */
    private function assertValidation(array $document, AgentResultContext $context, AgentResultValidationError $reason, string $label = ''): void
    {
        try {
            $this->app->make(AgentResultValidator::class)->validate(json_encode($document, JSON_THROW_ON_ERROR), $context, $this->redactionContext());
            self::fail(sprintf('The invalid agent result was accepted. %s', $label));
        } catch (AgentResultValidationException $exception) {
            self::assertSame($reason, $exception->reason, $label);
        }
    }

    /** @return array<string, mixed> */
    private function document(AgentResultContext $context, AgentResultStatus $status): array
    {
        return [
            'schema_version' => $context->role === AgentRole::IMPLEMENTATION ? 'ai6.agent.v1' : 'ai6.quality-review.v1',
            'status' => $status->value,
            'summary' => 'Prüfbares Ergebnis.',
            'prompt_snapshot_hash' => $context->promptSnapshot->hash,
            'instruction_snapshot_hash' => $context->instructionSnapshot->hash,
            'provider_runtime_profile_hash' => $context->runtimeProfile->hash,
        ];
    }

    private function context(
        AgentRole $role,
        string $diff = '',
        bool $instructionUpdate = false,
        string $scopedPath = 'instructions/agent.md',
        ?string $expectedBlobSha = null,
    ): AgentResultContext {
        $prompt = $this->app->make(PromptRenderer::class)->snapshot([
            new PromptRenderRequest('implementation', new PromptVariables(['context' => 'Test'])),
        ], $this->redactionContext());
        $entry = new InstructionSnapshotEntry('agent', 'repository', 1, 'instructions/agent.md', str_repeat('a', 40), 'Anweisung', []);

        return new AgentResultContext(
            $role,
            $prompt,
            new InstructionSnapshot('fake', [$entry], str_repeat('b', 64)),
            new ProviderRuntimeProfile('fake-v1', 1, [], [], [], str_repeat('c', 64)),
            ['AC-01', 'AC-02'],
            $diff,
            $instructionUpdate,
            [$scopedPath],
            [$scopedPath => $scopedPath === 'instructions/agent.md' ? str_repeat('a', 40) : $expectedBlobSha],
        );
    }

    private function redactionContext(): RedactionContext
    {
        return new RedactionContext('project-test', 'run-test', 'agent-result');
    }
}
