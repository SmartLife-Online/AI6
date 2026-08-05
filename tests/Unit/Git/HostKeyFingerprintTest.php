<?php

namespace Tests\Unit\Git;

use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitConfigurationFactory;
use App\AI6\Git\HostKeyFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostKeyFingerprintTest extends TestCase
{
    public function test_prefix_case_and_optional_canonical_padding_preserve_the_digest(): void
    {
        $digest = random_bytes(32);
        $payload = rtrim(base64_encode($digest), '=');

        $canonical = HostKeyFingerprint::parse('SHA256:'.$payload)['fingerprint'];
        $lowerPrefix = HostKeyFingerprint::parse('sha256:'.$payload.'=')['fingerprint'];

        self::assertInstanceOf(HostKeyFingerprint::class, $canonical);
        self::assertInstanceOf(HostKeyFingerprint::class, $lowerPrefix);
        self::assertTrue($canonical->matches($lowerPrefix));
        self::assertSame('SHA256:'.$payload, $lowerPrefix->canonical());
    }

    #[DataProvider('invalidFingerprints')]
    public function test_invalid_formats_return_the_internal_format_class(string $fingerprint): void
    {
        $result = HostKeyFingerprint::parse($fingerprint);

        self::assertNull($result['fingerprint']);
        self::assertSame('format_error', $result['failure_class']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFingerprints(): iterable
    {
        yield '31 bytes' => ['SHA256:'.rtrim(base64_encode(str_repeat('a', 31)), '=')];
        yield '33 bytes' => ['SHA256:'.rtrim(base64_encode(str_repeat('a', 33)), '=')];
        yield 'excess padding' => ['SHA256:'.rtrim(base64_encode(str_repeat('a', 32)), '=').'=='];
        yield 'non canonical pad bits' => ['SHA256:'.str_repeat('A', 42).'AB='];
        yield 'invalid alphabet' => ['SHA256:'.str_repeat('A', 42).'-='];
        yield 'wrong prefix' => ['SHA512:'.rtrim(base64_encode(str_repeat('a', 32)), '=')];
        yield 'truncated' => ['SHA256:'.substr(rtrim(base64_encode(str_repeat('a', 32)), '='), 0, 42)];
    }

    public function test_payload_case_and_digest_bits_are_not_normalized(): void
    {
        $digest = str_repeat("\x01", 32);
        $payload = rtrim(base64_encode($digest), '=');
        $caseChanged = $this->changePayloadCase($payload);
        $bitChanged = $digest;
        $bitChanged[0] = chr(ord($bitChanged[0]) ^ 1);

        $expected = HostKeyFingerprint::parse('SHA256:'.$payload)['fingerprint'];
        $changedCase = HostKeyFingerprint::parse('SHA256:'.$caseChanged)['fingerprint'];
        $changedBit = HostKeyFingerprint::parse('SHA256:'.rtrim(base64_encode($bitChanged), '='))['fingerprint'];

        self::assertInstanceOf(HostKeyFingerprint::class, $expected);
        self::assertInstanceOf(HostKeyFingerprint::class, $changedCase);
        self::assertInstanceOf(HostKeyFingerprint::class, $changedBit);
        self::assertFalse($expected->matches($changedCase));
        self::assertFalse($expected->matches($changedBit));
    }

    public function test_digest_comparison_is_bound_to_hash_equals(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Git/HostKeyFingerprint.php');

        self::assertIsString($source);
        self::assertSame(1, preg_match(
            '/function\s+matches\s*\([^)]*\)\s*:\s*bool\s*\{(?<body>.*?)\n\s*\}/s',
            $source,
            $matches,
        ));
        self::assertMatchesRegularExpression('/\bhash_equals\s*\(/', $matches['body']);
    }

    public function test_configuration_pins_use_the_same_parser_and_are_canonicalized(): void
    {
        $payload = rtrim(base64_encode(random_bytes(32)), '=');
        $configuration = (new GitConfigurationFactory)->inspect([
            'binary' => '/usr/bin/git',
            'ssh_binary' => '/usr/bin/ssh',
            'executable_path' => '/usr/local/bin:/usr/bin:/bin',
            'ssh_wrapper' => __FILE__,
            'execution_home' => __DIR__,
            'xdg_config_home' => __DIR__,
            'global_config' => __FILE__,
            'hooks_path' => __DIR__,
            'allowed_hosts' => 'git.example.test',
            'allowed_remote_paths' => 'acme/project.git',
            'allowed_ref_patterns' => 'refs/heads/*',
            'pinned_host_keys' => 'git.example.test=sha256:'.$payload.'=',
        ]);

        self::assertInstanceOf(GitConfiguration::class, $configuration);
        self::assertSame(
            ['git.example.test' => ['SHA256:'.$payload]],
            $configuration->pinnedHostKeyFingerprints,
        );
    }

    private function changePayloadCase(string $payload): string
    {
        for ($index = 0; $index < strlen($payload); $index++) {
            if (ctype_alpha($payload[$index])) {
                $payload[$index] = ctype_upper($payload[$index])
                    ? strtolower($payload[$index])
                    : strtoupper($payload[$index]);

                return $payload;
            }
        }

        self::fail('The fixture payload unexpectedly contains no alphabetic Base64 character.');
    }
}
