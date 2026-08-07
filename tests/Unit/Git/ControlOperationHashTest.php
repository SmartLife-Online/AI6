<?php

namespace Tests\Unit\Git;

use App\AI6\Git\CanonicalJson;
use App\AI6\Git\CanonicalRequestException;
use App\AI6\Git\ControlOperationHasher;
use App\AI6\Git\ControlOperationType;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ControlOperationHashTest extends TestCase
{
    public function test_canonical_json_normalizes_recursively_and_uses_jcs_order_and_escapes(): void
    {
        $canonical = new CanonicalJson;
        $decomposed = "e\u{0301}";

        self::assertSame(
            '{"a":{"quote":"\"\\\\","x":1},"z":["é",true]}',
            $canonical->normalizeAndEncode([
                'z' => [$decomposed, true],
                'a' => ['x' => 1, 'quote' => '"\\'],
            ]),
        );
    }

    public function test_normalized_key_collisions_and_unsupported_numbers_are_rejected(): void
    {
        $canonical = new CanonicalJson;

        try {
            $canonical->normalizeAndEncode(["e\u{0301}" => 1, 'é' => 2]);
            self::fail('Unicode-normalized duplicate keys were accepted.');
        } catch (CanonicalRequestException $exception) {
            self::assertStringContainsString('collision', $exception->getMessage());
        }

        $this->expectException(CanonicalRequestException::class);
        $canonical->normalizeAndEncode(['fraction' => 1.5]);
    }

    public function test_request_hash_has_a_fixed_vector_and_unambiguous_presence_and_lengths(): void
    {
        $canonical = new CanonicalJson;
        $hasher = new ControlOperationHasher;
        $snapshot = $canonical->normalizeAndEncode(['active' => true, 'actor_id' => 42]);
        $parameters = ['algorithm' => 'ed25519'];

        $hash = $hasher->hash(
            1,
            str_repeat('a', 32),
            ControlOperationType::DEPLOY_KEY_PROVISION,
            '42',
            $snapshot,
            null,
            $parameters,
        );

        self::assertSame('a8bc82f8a5893219e4b5c04d2f8be7e9cd07273ad042c342898978b768eb8884', $hash);
        self::assertNotSame($hash, $hasher->hash(
            1,
            str_repeat('a', 32),
            ControlOperationType::DEPLOY_KEY_PROVISION,
            '42',
            $snapshot,
            '',
            $parameters,
        ));
        self::assertNotSame(
            $hasher->hash(1, 'ab', ControlOperationType::DEPLOY_KEY_PROVISION, 'c', $snapshot, null, $parameters),
            $hasher->hash(1, 'a', ControlOperationType::DEPLOY_KEY_PROVISION, 'bc', $snapshot, null, $parameters),
        );
    }

    public function test_nfc_equivalent_request_fields_produce_the_same_hash(): void
    {
        $hasher = new ControlOperationHasher;
        $canonical = new CanonicalJson;
        $snapshot = $canonical->normalizeAndEncode(['scope' => 'admin']);
        $parameters = ['algorithm' => 'ed25519'];

        self::assertSame(
            $hasher->hash(1, "proje\u{0301}ct", ControlOperationType::DEPLOY_KEY_PROVISION, '1', $snapshot, null, $parameters),
            $hasher->hash(1, 'projéct', ControlOperationType::DEPLOY_KEY_PROVISION, '1', $snapshot, null, $parameters),
        );
    }

    public function test_numeric_object_keys_remain_distinct_from_arrays(): void
    {
        $canonical = new CanonicalJson;
        $numericObject = json_decode('{"0":"a","1":"b"}', false, 8, JSON_THROW_ON_ERROR);
        $sparseObject = json_decode('{"1":"a"}', false, 8, JSON_THROW_ON_ERROR);

        self::assertSame('{"0":"a","1":"b"}', $canonical->normalizeAndEncode($numericObject));
        self::assertSame('{"1":"a"}', $canonical->normalizeAndEncode($sparseObject));
        self::assertSame('{"1":"a"}', $canonical->normalizeAndEncode([1 => 'a']));
        self::assertSame('["a","b"]', $canonical->normalizeAndEncode(['a', 'b']));
        self::assertNotSame(
            $canonical->normalizeAndEncode($numericObject),
            $canonical->normalizeAndEncode(['a', 'b']),
        );

        // The object contract is stable under repeated decode/encode cycles, which
        // is what the persisted snapshot verification relies on.
        $reencoded = $canonical->normalizeAndEncode(
            json_decode($canonical->normalizeAndEncode($numericObject), false, 8, JSON_THROW_ON_ERROR),
        );
        self::assertSame('{"0":"a","1":"b"}', $reencoded);
    }

    public function test_the_persisted_snapshot_verification_decodes_objects_and_never_associative_arrays(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/AI6/Git/ControlOperationAuthorizationSnapshot.php',
        );
        self::assertIsString($source);
        self::assertStringContainsString('json_decode($operation->authorization_snapshot_jcs, false,', $source);
        self::assertStringNotContainsString('json_decode($operation->authorization_snapshot_jcs, true', $source);
    }

    public function test_snapshot_canonicalization_supports_characters_outside_the_bmp(): void
    {
        self::assertSame(
            '{"emoji":"😀","music":"𝄞"}',
            (new CanonicalJson)->normalizeAndEncode(['music' => '𝄞', 'emoji' => '😀']),
        );
    }

    public function test_line_terminators_and_control_characters_are_encoded_per_rfc_8785(): void
    {
        // RFC 8785 §3.2.2.2 does not require U+2028/U+2029 to be escaped, so they
        // must appear as literal UTF-8 bytes, while control characters below
        // U+0020 remain \uXXXX-escaped (lowercase hex).
        $value = "\u{2028}\u{2029}\u{0000}\u{001f}";

        self::assertSame(
            "\"\u{2028}\u{2029}\\u0000\\u001f\"",
            (new CanonicalJson)->normalizeAndEncode($value),
        );
    }

    public function test_named_and_numeric_control_character_escapes_are_encoded_per_rfc_8785(): void
    {
        // Distinct from the line-terminator vector above: this covers the named
        // short escapes (backspace, form feed, newline, CR, tab) plus the
        // numeric control-character escape range (U+0001 through U+001F).
        $value = "\u{0008}\u{000C}\u{000A}\u{000D}\u{0009}\u{0001}\u{001f}";

        self::assertSame(
            '"\\b\\f\\n\\r\\t\\u0001\\u001f"',
            (new CanonicalJson)->normalizeAndEncode($value),
        );
    }

    public function test_object_keys_outside_the_bmp_sort_by_utf16_code_unit_not_code_point(): void
    {
        // RFC 8785 §3.2.3 sorts property names as arrays of UTF-16 code units, so
        // U+10000 (lead surrogate 0xD800) must sort before U+FF61 (0xFF61) even
        // though its code point (65536) is larger. The RFC's own §3.2.3 example
        // orders U+1F600 before U+FB33 for the same reason; a code-point (UTF-32)
        // comparison would wrongly place the U+FF61 key first.
        $object = new stdClass;
        $keyU10000 = "k\u{10000}";
        $keyUff61 = "k\u{FF61}";
        $object->$keyU10000 = 'b';
        $object->$keyUff61 = 'a';

        self::assertSame(
            '{"k𐀀":"b","k｡":"a"}',
            (new CanonicalJson)->normalizeAndEncode($object),
        );
    }
}
