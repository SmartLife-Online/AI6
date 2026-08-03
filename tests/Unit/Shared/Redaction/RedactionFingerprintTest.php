<?php

namespace Tests\Unit\Shared\Redaction;

use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionKeyringFactory;
use App\AI6\Shared\Redaction\RedactionMatchType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RedactionFingerprintTest extends TestCase
{
    public function test_fingerprint_reproduces_the_committed_hmac_golden_vector(): void
    {
        $fixture = $this->fixture();
        $key = hex2bin($fixture['key_hex']);
        self::assertIsString($key);
        $keyring = new RedactionKeyring($fixture['key_id'], [
            $fixture['key_id'] => ['version' => $fixture['version'], 'key' => $key],
        ]);
        $fingerprint = (new RedactionFingerprintGenerator($keyring))->generate(
            RedactionMatchType::from($fixture['match_type']),
            new RedactionContext($fixture['project_id'], $fixture['run_id'], $fixture['context_identifier']),
            $fixture['removed_value'],
        );

        self::assertSame($fixture['version'], $fingerprint->version);
        self::assertSame($fixture['key_id'], $fingerprint->keyId);
        self::assertSame($fixture['expected_hmac_sha256'], $fingerprint->value);
    }

    public function test_same_value_is_isolated_across_projects_and_runs(): void
    {
        $generator = new RedactionFingerprintGenerator($this->keyring());
        $fingerprints = [];

        foreach (['project-a', 'project-b'] as $projectId) {
            foreach (['run-a', 'run-b'] as $runId) {
                $fingerprints[] = $generator->generate(
                    RedactionMatchType::SECRET,
                    new RedactionContext($projectId, $runId, 'provider-output'),
                    'same-private-value',
                )->value;
            }
        }

        self::assertCount(4, array_unique($fingerprints));
    }

    public function test_rotation_changes_version_key_id_and_new_fingerprint_without_mutating_old_results(): void
    {
        $entries = [
            'key-v1' => ['version' => 1, 'key' => str_repeat('a', 32)],
            'key-v2' => ['version' => 2, 'key' => str_repeat('b', 32)],
        ];
        $context = new RedactionContext('project-a', 'run-a', 'log');
        $old = (new RedactionFingerprintGenerator(new RedactionKeyring('key-v1', $entries)))
            ->generate(RedactionMatchType::TOKEN, $context, 'private-token');
        $oldSnapshot = clone $old;
        $new = (new RedactionFingerprintGenerator(new RedactionKeyring('key-v2', $entries)))
            ->generate(RedactionMatchType::TOKEN, $context, 'private-token');

        self::assertEquals($oldSnapshot, $old);
        self::assertSame(1, $old->version);
        self::assertSame('key-v1', $old->keyId);
        self::assertSame(2, $new->version);
        self::assertSame('key-v2', $new->keyId);
        self::assertNotSame($old->value, $new->value);
        self::assertSame(['__construct', 'generate'], array_map(
            static fn ($method): string => $method->getName(),
            (new ReflectionClass(RedactionFingerprintGenerator::class))->getMethods(),
        ));
    }

    public function test_fingerprint_is_not_plaintext_or_an_unkeyed_digest_and_cannot_be_reproduced_without_the_key(): void
    {
        $value = 'short-private-value';
        $fingerprint = (new RedactionFingerprintGenerator($this->keyring()))->generate(
            RedactionMatchType::SECRET,
            new RedactionContext('project-a', null, 'ticket'),
            $value,
        );
        $stored = get_object_vars($fingerprint);

        self::assertFalse(in_array($value, $stored, true));
        self::assertFalse(in_array(hash('sha256', $value), $stored, true));
        self::assertNotSame(hash('sha256', $value), $fingerprint->value);
        self::assertNotSame(
            hash_hmac('sha256', $value, str_repeat('x', 32)),
            $fingerprint->value,
        );
    }

    public function test_fingerprint_framing_distinguishes_field_boundaries_and_normalizes_nfc(): void
    {
        $generator = new RedactionFingerprintGenerator($this->keyring());
        $left = $generator->generate(
            RedactionMatchType::TOKEN,
            new RedactionContext('c', null, 'ab'),
            'value',
        );
        $right = $generator->generate(
            RedactionMatchType::TOKEN,
            new RedactionContext('bc', null, 'a'),
            'value',
        );
        $nfc = $generator->generate(
            RedactionMatchType::TOKEN,
            new RedactionContext('project', 'run', 'log'),
            'é',
        );
        $nfd = $generator->generate(
            RedactionMatchType::TOKEN,
            new RedactionContext('project', 'run', 'log'),
            "e\u{0301}",
        );

        self::assertNotSame($left->value, $right->value);
        self::assertSame($nfc->value, $nfd->value);
    }

    public function test_keyring_factory_supports_multiple_versioned_keys_and_safe_rotation_errors(): void
    {
        $secret = 'do-not-echo-private-key';
        $configuration = [
            'active_key_id' => 'key-v2',
            'keys' => json_encode([
                'key-v1' => ['version' => 1, 'key' => 'base64:'.base64_encode(str_repeat('a', 32))],
                'key-v2' => ['version' => 2, 'key' => 'base64:'.base64_encode(str_repeat('b', 32))],
            ], JSON_THROW_ON_ERROR),
            'app_key' => $secret,
        ];
        $keyring = (new RedactionKeyringFactory)->inspect($configuration);

        self::assertInstanceOf(RedactionKeyring::class, $keyring);
        self::assertSame('key-v2', $keyring->activeKeyId());
        self::assertSame(2, $keyring->activeVersion());
        self::assertSame(['key-v1', 'key-v2'], $keyring->keyIds());
        self::assertFalse($keyring->usesApplicationKeyFallback());

        $configuration['keys'] = $secret;
        $violation = (new RedactionKeyringFactory)->inspect($configuration);
        self::assertInstanceOf(ConfigurationViolation::class, $violation);
        self::assertStringNotContainsString($secret, $violation->message);
    }

    public function test_keyring_factory_rejects_purely_numeric_key_ids_explicitly(): void
    {
        $factory = new RedactionKeyringFactory;
        $encodedKey = base64_encode(str_repeat('k', 32));
        $result = $factory->inspect([
            'active_key_id' => '2026',
            'keys' => sprintf(
                '{"2026":{"version":1,"key":"base64:%s"}}',
                $encodedKey,
            ),
        ]);

        self::assertInstanceOf(ConfigurationViolation::class, $result);
        self::assertSame('AI6_REDACTION_ACTIVE_KEY_ID must not be purely numeric.', $result->message);

        $result = $factory->inspect([
            'active_key_id' => 'key-v1',
            'keys' => sprintf(
                '{"key-v1":{"version":1,"key":"base64:%1$s"},"2026":{"version":2,"key":"base64:%1$s"}}',
                $encodedKey,
            ),
        ]);

        self::assertInstanceOf(ConfigurationViolation::class, $result);
        self::assertSame('AI6_REDACTION_KEYS key IDs must not be purely numeric.', $result->message);
    }

    public function test_derived_application_keyring_is_explicitly_marked_as_a_local_fallback(): void
    {
        $keyring = (new RedactionKeyringFactory)->inspect([
            'environment' => 'local',
            'active_key_id' => 'app-key-v1',
            'keys' => '',
            'app_key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);

        self::assertInstanceOf(RedactionKeyring::class, $keyring);
        self::assertTrue($keyring->usesApplicationKeyFallback());
    }

    public function test_application_key_fallback_is_rejected_in_production_and_by_secure_default(): void
    {
        $configuration = [
            'environment' => 'production',
            'active_key_id' => 'app-key-v1',
            'keys' => '',
            'app_key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ];

        foreach ([$configuration, array_diff_key($configuration, ['environment' => true])] as $candidate) {
            $result = (new RedactionKeyringFactory)->inspect($candidate);

            self::assertInstanceOf(ConfigurationViolation::class, $result);
            self::assertStringContainsString('AI6_REDACTION_KEYS', $result->message);
            self::assertStringNotContainsString($configuration['app_key'], $result->message);
        }
    }

    private function keyring(): RedactionKeyring
    {
        return new RedactionKeyring('test-v1', [
            'test-v1' => ['version' => 1, 'key' => str_repeat("\x01", 32)],
        ]);
    }

    /** @return array{key_id: string, version: int, key_hex: string, match_type: string, context_identifier: string, project_id: string, run_id: string, removed_value: string, expected_hmac_sha256: string} */
    private function fixture(): array
    {
        $contents = file_get_contents(__DIR__.'/Fixtures/fingerprint.json');
        self::assertNotFalse($contents);
        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);

        return $fixture;
    }
}
