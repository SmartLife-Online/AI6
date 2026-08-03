<?php

namespace App\AI6\Shared\Redaction;

use InvalidArgumentException;

final readonly class RedactionPolicy
{
    /** @var list<RedactionRule> */
    private array $rules;

    /** @param list<RedactionRule> $rules */
    public function __construct(array $rules)
    {
        if ($rules === []) {
            throw new InvalidArgumentException('A RedactionPolicy requires at least one rule.');
        }

        $priorities = array_map(
            static fn (RedactionRule $rule): int => $rule->priority,
            $rules,
        );

        if (count(array_unique($priorities)) !== count($priorities)) {
            throw new InvalidArgumentException('Every redaction rule requires a unique priority.');
        }

        $this->rules = $rules;
    }

    /** @return list<RedactionRule> */
    public function rules(): array
    {
        return $this->rules;
    }
}
