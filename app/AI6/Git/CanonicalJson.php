<?php

namespace App\AI6\Git;

use JsonException;
use Normalizer;
use stdClass;

final class CanonicalJson
{
    /**
     * A JSON object must be passed as `stdClass`, never as an associative PHP
     * array: an array cannot express an object whose keys are exactly "0" … "n-1",
     * so such a document would silently canonicalize to a JSON array. Callers
     * therefore decode JSON with `json_decode($json, false)`; only a PHP list is
     * accepted as a JSON array.
     */
    public function normalizeAndEncode(mixed $value): string
    {
        return $this->encode($this->normalize($value));
    }

    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

            if (! is_string($normalized)) {
                throw new CanonicalRequestException('A request string could not be normalized.');
            }

            return $normalized;
        }

        if ($value instanceof stdClass) {
            return $this->normalizeObject($value);
        }

        if (! is_array($value)) {
            if (is_float($value) || (! is_null($value) && ! is_bool($value) && ! is_int($value))) {
                throw new CanonicalRequestException('The authorization snapshot contains an unsupported value.');
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        return $this->normalizeObject($value);
    }

    /** @param array<array-key, mixed>|stdClass $value */
    private function normalizeObject(array|stdClass $value): CanonicalJsonObject
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            $key = is_int($key) ? (string) $key : $key;

            $normalizedKey = $this->normalize($key);
            if (isset($normalized[$normalizedKey])) {
                throw new CanonicalRequestException('Unicode normalization produced an authorization snapshot key collision.');
            }

            $normalized[$normalizedKey] = new CanonicalJsonMember($normalizedKey, $this->normalize($item));
        }

        return new CanonicalJsonObject(array_values($normalized));
    }

    private function encode(mixed $value): string
    {
        if (is_array($value)) {
            return '['.implode(',', array_map(fn (mixed $item): string => $this->encode($item), $value)).']';
        }

        if ($value instanceof CanonicalJsonObject) {
            // RFC 8785 §3.2.3 sorts property names "formatted as arrays of UTF-16 code
            // units ... treated as unsigned integers", NOT by Unicode code point. A
            // surrogate pair's lead unit (e.g. U+10000 -> 0xD800) therefore sorts ahead
            // of a BMP character with a larger code unit (e.g. U+FF61); the RFC's own
            // §3.2.3 example places U+1F600 before U+FB33 for exactly this reason.
            // Big-endian UTF-16 bytes compared bytewise reproduce that code-unit order.
            $members = $value->members;
            usort($members, static fn (CanonicalJsonMember $left, CanonicalJsonMember $right): int => strcmp(
                mb_convert_encoding($left->key, 'UTF-16BE', 'UTF-8'),
                mb_convert_encoding($right->key, 'UTF-16BE', 'UTF-8'),
            ));

            $encoded = [];
            foreach ($members as $member) {
                $encoded[] = $this->encodeString($member->key).':'.$this->encode($member->value);
            }

            return '{'.implode(',', $encoded).'}';
        }

        if (is_string($value)) {
            return $this->encodeString($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }

    private function encodeString(string $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        } catch (JsonException $exception) {
            throw new CanonicalRequestException('A request string could not be encoded.', previous: $exception);
        }
    }
}

/** @internal */
final readonly class CanonicalJsonObject
{
    /** @param list<CanonicalJsonMember> $members */
    public function __construct(public array $members) {}
}

/** @internal */
final readonly class CanonicalJsonMember
{
    public function __construct(
        public string $key,
        public mixed $value,
    ) {}
}
