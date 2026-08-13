<?php

namespace App\AI6\Prompts;

use InvalidArgumentException;

final readonly class PromptVariables
{
    /** @var array<string, string> */
    public array $values;

    /** @param array<array-key, mixed> $values */
    public function __construct(array $values)
    {
        if (array_is_list($values)) {
            throw new InvalidArgumentException('Prompt variables must be a typed mapping.');
        }
        $validated = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1 || ! is_string($value)) {
                throw new InvalidArgumentException('Prompt variables contain an invalid typed field.');
            }
            $validated[$key] = $value;
        }
        ksort($validated, SORT_STRING);
        $this->values = $validated;
    }
}
