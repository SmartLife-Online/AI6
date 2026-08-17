<?php

namespace App\AI6\Shared\Json;

use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use JsonException;

final class RestrictedJsonDecoder
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly ProcessPolicyRegistry $policies,
        private readonly int $maximumDepth = 16,
        private readonly int $maximumElements = 1000,
    ) {}

    /** @return array<string, mixed>|list<mixed> */
    public function decode(string $bytes, RedactionContext $context): array
    {
        if (strlen($bytes) > $this->policies->get(ProcessPolicyName::AGENT)->outputLimitBytes) {
            throw new JsonDecodingException(JsonDecodingError::SIZE_EXCEEDED);
        }

        try {
            $input = $this->redactor->redact($bytes, $context)->text;
            if (strlen($input) > $this->policies->get(ProcessPolicyName::AGENT)->outputLimitBytes) {
                throw new JsonDecodingException(JsonDecodingError::SIZE_EXCEEDED);
            }
        } catch (InvalidRedactionInputException) {
            throw new JsonDecodingException(JsonDecodingError::INVALID_UTF8);
        }

        $position = 0;
        $elements = 0;
        $this->skipWhitespace($input, $position);
        $decoded = $this->parseValue($input, $position, $elements, 0);
        $this->skipWhitespace($input, $position);
        if ($position !== strlen($input) || ! is_array($decoded)) {
            throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
        }

        return $decoded;
    }

    private function parseValue(string $input, int &$position, int &$elements, int $depth): mixed
    {
        if ($depth > $this->maximumDepth || $position >= strlen($input)) {
            throw new JsonDecodingException($depth > $this->maximumDepth ? JsonDecodingError::NESTING_EXCEEDED : JsonDecodingError::INVALID_JSON);
        }

        return match ($input[$position]) {
            '{' => $this->parseObject($input, $position, $elements, $depth + 1),
            '[' => $this->parseArray($input, $position, $elements, $depth + 1),
            '"' => $this->parseString($input, $position),
            't' => $this->parseLiteral($input, $position, 'true', true),
            'f' => $this->parseLiteral($input, $position, 'false', false),
            'n' => $this->parseLiteral($input, $position, 'null', null),
            default => $this->parseNumber($input, $position),
        };
    }

    /** @return array<string, mixed> */
    private function parseObject(string $input, int &$position, int &$elements, int $depth): array
    {
        $position++;
        $this->skipWhitespace($input, $position);
        $object = [];
        if ($this->consume($input, $position, '}')) {
            return $object;
        }

        while (true) {
            if ($position >= strlen($input) || $input[$position] !== '"') {
                throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
            }
            $key = $this->parseString($input, $position);
            if (array_key_exists($key, $object)) {
                throw new JsonDecodingException(JsonDecodingError::DUPLICATE_KEY);
            }
            $this->consumeElement($elements);
            $this->skipWhitespace($input, $position);
            if (! $this->consume($input, $position, ':')) {
                throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
            }
            $this->skipWhitespace($input, $position);
            $object[$key] = $this->parseValue($input, $position, $elements, $depth);
            $this->skipWhitespace($input, $position);
            if ($this->consume($input, $position, '}')) {
                return $object;
            }
            if (! $this->consume($input, $position, ',')) {
                throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
            }
            $this->skipWhitespace($input, $position);
        }
    }

    /** @return list<mixed> */
    private function parseArray(string $input, int &$position, int &$elements, int $depth): array
    {
        $position++;
        $this->skipWhitespace($input, $position);
        $array = [];
        if ($this->consume($input, $position, ']')) {
            return $array;
        }

        while (true) {
            $this->consumeElement($elements);
            $array[] = $this->parseValue($input, $position, $elements, $depth);
            $this->skipWhitespace($input, $position);
            if ($this->consume($input, $position, ']')) {
                return $array;
            }
            if (! $this->consume($input, $position, ',')) {
                throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
            }
            $this->skipWhitespace($input, $position);
        }
    }

    private function parseString(string $input, int &$position): string
    {
        $start = $position++;
        $escaped = false;
        while ($position < strlen($input)) {
            $character = $input[$position++];
            if ($escaped) {
                $escaped = false;

                continue;
            }
            if ($character === '\\') {
                $escaped = true;

                continue;
            }
            if ($character === '"') {
                try {
                    $value = json_decode(substr($input, $start, $position - $start), true, 4, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
                }
                if (! is_string($value)) {
                    throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
                }

                return $value;
            }
            if (ord($character) < 0x20) {
                throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
            }
        }

        throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
    }

    private function parseLiteral(string $input, int &$position, string $literal, mixed $value): mixed
    {
        if (substr($input, $position, strlen($literal)) !== $literal) {
            throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
        }
        $position += strlen($literal);

        return $value;
    }

    private function parseNumber(string $input, int &$position): int|float
    {
        $tail = substr($input, $position);
        if (preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/D', $tail, $matches) !== 1) {
            throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
        }
        $position += strlen($matches[0]);
        try {
            $value = json_decode($matches[0], true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
        }
        if (! is_int($value) && ! is_float($value)) {
            throw new JsonDecodingException(JsonDecodingError::INVALID_JSON);
        }

        return $value;
    }

    private function consumeElement(int &$elements): void
    {
        $elements++;
        if ($elements > $this->maximumElements) {
            throw new JsonDecodingException(JsonDecodingError::ELEMENT_LIMIT_EXCEEDED);
        }
    }

    private function skipWhitespace(string $input, int &$position): void
    {
        while ($position < strlen($input) && str_contains(" \n\r\t", $input[$position])) {
            $position++;
        }
    }

    private function consume(string $input, int &$position, string $expected): bool
    {
        if ($position >= strlen($input) || $input[$position] !== $expected) {
            return false;
        }
        $position++;

        return true;
    }
}
