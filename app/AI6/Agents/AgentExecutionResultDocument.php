<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Redaction\RedactionContext;

final readonly class AgentExecutionResultDocument
{
    public function __construct(
        public AgentExecutionRequest $request,
        public string $bootId,
        public string $state,
        public string $reason,
        public int $completedAt,
        public string $answerHash,
        public AgentTurnResult $answer,
    ) {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $bootId) !== 1
            || ! in_array($state, ['ok', 'invalid_json', 'provider_error'], true)
            || preg_match('/\A[a-z][a-z0-9_]{0,95}\z/D', $reason) !== 1
            || $completedAt < 1 || $completedAt > $request->integer('deadline_at')
            || preg_match('/\A[0-9a-f]{64}\z/D', $answerHash) !== 1) {
            throw new AgentExecutionException('agent_result_binding_invalid');
        }
    }

    public static function fromJson(string $bytes): self
    {
        $fields = app(RestrictedJsonDecoder::class)->decode($bytes, new RedactionContext('agent', null, 'execution-result'));
        $keys = ['schema', 'request', 'agent_boot_id', 'state', 'reason', 'completed_at', 'answer_sha256', 'usage', 'usage_source'];
        if (count($fields) !== count($keys) || array_diff($keys, array_keys($fields)) !== []
            || $fields['schema'] !== 'ai6.agent-execution-result.v1'
            || ! is_array($fields['request']) || ! is_array($fields['usage'])
            || ! is_string($fields['agent_boot_id']) || ! is_string($fields['state'])
            || ! is_string($fields['reason']) || ! is_int($fields['completed_at'])
            || ! is_string($fields['answer_sha256']) || ! is_string($fields['usage_source'])) {
            throw new AgentExecutionException('agent_result_schema_invalid');
        }

        return new self(new AgentExecutionRequest($fields['request']), $fields['agent_boot_id'],
            $fields['state'], $fields['reason'], $fields['completed_at'], $fields['answer_sha256'],
            new AgentTurnResult('', $fields['usage'], $fields['usage_source']));
    }

    public function toJson(): string
    {
        return json_encode([
            'schema' => 'ai6.agent-execution-result.v1', 'request' => $this->request->fields,
            'agent_boot_id' => $this->bootId, 'state' => $this->state, 'reason' => $this->reason,
            'completed_at' => $this->completedAt, 'answer_sha256' => $this->answerHash,
            ...$this->answer->metadata(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }
}
