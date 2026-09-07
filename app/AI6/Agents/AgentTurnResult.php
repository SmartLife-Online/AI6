<?php

namespace App\AI6\Agents;

/** The provider's answer and reported usage, before central result validation. */
final readonly class AgentTurnResult
{
    /** @var array<string, int|float|null> */
    public array $usage;

    /** @param array<array-key, mixed> $usage */
    public function __construct(
        public string $bytes,
        array $usage = [],
        public string $usageSource = 'unknown',
    ) {
        if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $usageSource) !== 1
            || count($usage) > 32 || ($usageSource === 'unknown' && $usage !== [])) {
            throw new AgentExecutionException('agent_usage_invalid');
        }
        $validated = [];
        foreach ($usage as $key => $value) {
            if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1
                || ($value !== null && ((! is_int($value) && ! is_float($value))
                    || ! is_finite((float) $value) || $value < 0 || $value > 9007199254740991))) {
                throw new AgentExecutionException('agent_usage_invalid');
            }
            $validated[$key] = $value;
        }
        $this->usage = $validated;
    }

    /** @return array{usage: array<string, int|float|null>, usage_source: string} */
    public function metadata(): array
    {
        return ['usage' => $this->usage, 'usage_source' => $this->usageSource];
    }
}
