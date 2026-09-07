<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Redaction\RedactionContext;

/** Closed transport binding; executable selection never comes from the envelope. */
final readonly class AgentExecutionRequest
{
    /** @param array<string, mixed> $fields */
    public function __construct(public array $fields)
    {
        $keys = ['schema', 'execution_id', 'run_id', 'slot_id', 'session_id', 'role', 'attempt',
            'home', 'context_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_id',
            'runtime_profile_hash', 'provider_alias', 'credential_revision', 'deadline_at'];
        if (count($fields) !== count($keys) || array_diff($keys, array_keys($fields)) !== []
            || $fields['schema'] !== 'ai6.agent-execution.v1') {
            throw new AgentExecutionException('agent_request_schema_invalid');
        }
        foreach (['execution_id', 'run_id', 'slot_id', 'session_id', 'runtime_profile_id', 'provider_alias', 'credential_revision'] as $key) {
            if (! is_string($fields[$key]) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $fields[$key]) !== 1) {
                throw new AgentExecutionException('agent_request_identifier_invalid');
            }
        }
        foreach (['execution_id', 'context_hash', 'prompt_hash', 'instruction_hash', 'runtime_profile_hash'] as $key) {
            if (! is_string($fields[$key]) || preg_match('/\A[0-9a-f]{64}\z/D', $fields[$key]) !== 1) {
                throw new AgentExecutionException('agent_request_hash_invalid');
            }
        }
        if (! is_string($fields['role']) || AgentRole::tryFrom($fields['role']) === null
            || ! is_int($fields['attempt']) || $fields['attempt'] < 1 || $fields['attempt'] > 1000
            || ! is_int($fields['deadline_at']) || $fields['deadline_at'] < 1
            || ! is_string($fields['home'])
            || preg_match('/\Aexecution-[0-9a-f]{32}\/[A-Za-z0-9][A-Za-z0-9._-]{0,255}\z/D', $fields['home']) !== 1
            || ! str_starts_with($fields['home'], 'execution-'.substr($fields['execution_id'], 0, 32).'/')) {
            throw new AgentExecutionException('agent_request_binding_invalid');
        }
    }

    public static function fromJson(string $bytes): self
    {
        return new self(app(RestrictedJsonDecoder::class)->decode($bytes, new RedactionContext('agent', null, 'execution-request')));
    }

    public function toJson(): string
    {
        return json_encode($this->fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    public function string(string $key): string
    {
        $value = $this->fields[$key] ?? null;

        return is_string($value) ? $value : throw new AgentExecutionException('agent_request_field_invalid');
    }

    public function integer(string $key): int
    {
        $value = $this->fields[$key] ?? null;

        return is_int($value) ? $value : throw new AgentExecutionException('agent_request_field_invalid');
    }

    public function deliveryId(): string
    {
        return hash('sha256', "agent-execution\0".$this->string('execution_id'));
    }
}
