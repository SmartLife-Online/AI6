<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentExecutionRequest;
use App\AI6\Agents\AgentExecutionResultDocument;
use App\AI6\Agents\AgentResultContext;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\AgentTurnResult;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Prompts\PromptSnapshot;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AgentExecutionDocumentTest extends TestCase
{
    public function test_request_and_result_preserve_every_binding_and_reported_usage(): void
    {
        $request = $this->request();
        $decoded = AgentExecutionRequest::fromJson($request->toJson());
        self::assertSame($request->fields, $decoded->fields);
        self::assertSame($request->deliveryId(), $decoded->deliveryId());

        $answer = new AgentTurnResult('{"answer":true}', ['input_tokens' => 12, 'output_tokens' => 4, 'cost' => null], 'provider');
        foreach (['ok', 'invalid_json', 'provider_error'] as $state) {
            $document = new AgentExecutionResultDocument($request, str_repeat('b', 32), $state, 'agent_completed', 100, hash('sha256', $answer->bytes), $answer);
            $result = AgentExecutionResultDocument::fromJson($document->toJson());
            self::assertSame($request->fields, $result->request->fields);
            self::assertSame($document->bootId, $result->bootId);
            self::assertSame($state, $result->state);
            self::assertSame($document->answerHash, $result->answerHash);
            self::assertSame($answer->metadata(), $result->answer->metadata());
            self::assertSame('', $result->answer->bytes);
        }
        self::assertSame(['usage' => [], 'usage_source' => 'unknown'], (new AgentTurnResult(''))->metadata());
    }

    /** @param array<string, mixed> $replacement */
    #[DataProvider('invalidRequests')]
    public function test_request_rejects_changed_shape_types_and_path_bindings(array $replacement): void
    {
        $this->expectException(AgentExecutionException::class);
        new AgentExecutionRequest(array_replace($this->request()->fields, $replacement));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRequests(): array
    {
        return [
            'extra field' => [['command' => 'php']],
            'wrong schema' => [['schema' => 'v2']],
            'nonhex identity' => [['execution_id' => str_repeat('g', 64)]],
            'short identity' => [['execution_id' => str_repeat('a', 32)]],
            'wrong hash type' => [['context_hash' => 1]],
            'foreign home' => [['home' => 'execution-'.str_repeat('b', 32).'/slot-session']],
            'traversal' => [['home' => 'execution-'.str_repeat('a', 32).'/../other']],
            'unknown role' => [['role' => 'worker']],
            'string attempt' => [['attempt' => '1']],
            'zero attempt' => [['attempt' => 0]],
            'attempt limit' => [['attempt' => 1001]],
            'noninteger deadline' => [['deadline_at' => 12.5]],
            'empty session' => [['session_id' => '']],
            'empty model' => [['model' => '']],
            'free effort' => [['effort' => 'high; rm -rf /']],
        ];
    }

    public function test_transport_rejects_duplicate_keys_and_invalid_utf8_before_consumption(): void
    {
        foreach (['{"schema":"one","schema":"two"}', "{\"schema\":\"\xFF\"}"] as $bytes) {
            try {
                AgentExecutionRequest::fromJson($bytes);
                self::fail('The unsafe transport document was accepted.');
            } catch (JsonDecodingException $exception) {
                self::assertStringNotContainsString("\xFF", $exception->getMessage());
            }
        }
    }

    /** @param array<array-key, mixed> $usage */
    #[DataProvider('invalidUsage')]
    public function test_usage_never_accepts_nonfinite_negative_unbounded_or_untyped_values(array $usage, string $source): void
    {
        $this->expectException(AgentExecutionException::class);
        $this->expectExceptionMessage('agent_usage_invalid');
        new AgentTurnResult('', $usage, $source);
    }

    /** @return array<string, array{array<array-key, mixed>, string}> */
    public static function invalidUsage(): array
    {
        return [
            'unknown has no estimate' => [['tokens' => 0], 'unknown'],
            'negative' => [['tokens' => -1], 'provider'],
            'infinity' => [['cost' => INF], 'provider'],
            'nan' => [['cost' => NAN], 'provider'],
            'precision limit' => [['tokens' => 9007199254740992], 'provider'],
            'string number' => [['tokens' => '2'], 'provider'],
            'nested usage' => [['tokens' => ['count' => 2]], 'provider'],
            'numeric key' => [[3], 'provider'],
            'invalid source' => [[], 'password=secret'],
            'too many values' => [array_fill_keys(array_map(static fn (int $index): string => 'value_'.$index, range(1, 33)), 0), 'provider'],
        ];
    }

    public function test_context_roundtrip_rehydrates_existing_snapshot_objects_without_a_database(): void
    {
        $context = $this->context('Bound instructions and evidence.');
        $decoded = AgentResultContext::fromJson($context->toJson(), $this->app->make(ProviderRuntimeProfileRegistry::class), hash('sha256', $context->toJson()));
        self::assertEquals($context, $decoded);
        self::assertSame($context->toJson(), $decoded->toJson());
    }

    public function test_redaction_cannot_silently_change_the_bound_context(): void
    {
        $this->expectException(AgentExecutionException::class);
        $this->expectExceptionMessage('agent_context_invalid');
        $original = $this->context('password=provider-secret')->toJson();
        $changed = $this->app->make(Redactor::class)
            ->redact($original, new RedactionContext('agent', null, 'changed-context'))->text;
        self::assertNotSame($original, $changed);
        AgentResultContext::fromJson($changed, $this->app->make(ProviderRuntimeProfileRegistry::class), hash('sha256', $original));
    }

    public function test_a_sealed_snapshot_preserves_exact_approved_text_without_changing_provider_redaction(): void
    {
        $original = $this->context('password=provider-secret')->toJson();
        $decoded = AgentResultContext::fromJson($original, $this->app->make(ProviderRuntimeProfileRegistry::class), hash('sha256', $original));
        self::assertSame($original, $decoded->toJson());
        $response = $this->app->make(RestrictedJsonDecoder::class)->decode('{"message":"password=provider-secret "}', new RedactionContext('agent', null, 'provider-response'));
        self::assertStringNotContainsString('provider-secret', (string) $response['message']);
    }

    private function context(string $prompt): AgentResultContext
    {
        return new AgentResultContext(
            AgentRole::QUALITY_REVIEW,
            new PromptSnapshot('v1', ['review' => 'quality'], ['review' => $prompt], str_repeat('c', 64)),
            new InstructionSnapshot('fake', [], str_repeat('d', 64)),
            $this->app->make(ProviderRuntimeProfileRegistry::class)->get('fake-v1'),
            ['AC-01'], '', slotId: 'slot-1', expectedFindingIds: ['finding-1'], expectedFindingGroups: ['group-1'],
        );
    }

    private function request(): AgentExecutionRequest
    {
        return new AgentExecutionRequest([
            'schema' => 'ai6.agent-execution.v1', 'execution_id' => str_repeat('a', 64),
            'run_id' => 'run-1', 'slot_id' => 'slot-1', 'session_id' => 'session-1',
            'role' => AgentRole::QUALITY_REVIEW->value, 'attempt' => 1,
            'home' => 'execution-'.str_repeat('a', 32).'/slot-session',
            'context_hash' => str_repeat('b', 64), 'prompt_hash' => str_repeat('c', 64),
            'instruction_hash' => str_repeat('d', 64), 'runtime_profile_id' => 'fake-v1',
            'runtime_profile_hash' => str_repeat('e', 64), 'provider_alias' => 'fake',
            'model' => 'fake-model', 'effort' => 'medium',
            'credential_revision' => 'test-v1', 'deadline_at' => 200,
        ]);
    }
}
