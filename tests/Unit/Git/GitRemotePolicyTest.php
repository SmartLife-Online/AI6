<?php

namespace Tests\Unit\Git;

use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitRemotePolicy;
use App\AI6\Git\GitRemoteRejected;
use App\AI6\Git\KnownHostsVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GitRemotePolicyTest extends TestCase
{
    private string $knownHosts;

    private string $fingerprint;

    protected function setUp(): void
    {
        $key = random_bytes(48);
        $encoded = base64_encode($key);
        $this->fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', $key, true)), '=');
        $path = tempnam(sys_get_temp_dir(), 'ai6-known-hosts-');
        self::assertIsString($path);
        file_put_contents($path, 'git.example.test ssh-ed25519 '.$encoded."\n");
        $real = realpath($path);
        self::assertIsString($real);
        $this->knownHosts = $real;
    }

    protected function tearDown(): void
    {
        if (is_file($this->knownHosts)) {
            unlink($this->knownHosts);
        }
    }

    public function test_only_allowlisted_pinned_ssh_remotes_and_refs_are_accepted(): void
    {
        $policy = $this->policy();

        $uri = $policy->validate(
            'ssh://git@git.example.test/acme/project.git',
            'refs/heads/main',
            $this->knownHosts,
        );
        self::assertSame('git.example.test', $uri->host);
        self::assertSame('acme/project.git', $uri->path);

        $scp = $policy->validate(
            'git@git.example.test:acme/project.git',
            'refs/heads/feature/topic',
            $this->knownHosts,
        );
        self::assertSame('git.example.test', $scp->host);
    }

    #[DataProvider('rejectedRemotes')]
    public function test_rejected_remotes_return_a_named_reason_before_any_process_contract(string $remote, string $ref, string $reason): void
    {
        try {
            $this->policy()->validate($remote, $ref, $this->knownHosts);
            self::fail('The remote was unexpectedly accepted.');
        } catch (GitRemoteRejected $exception) {
            self::assertSame($reason, $exception->reason);
            self::assertStringNotContainsString($remote, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rejectedRemotes(): iterable
    {
        yield 'file' => ['file:///tmp/repository.git', 'refs/heads/main', 'protocol_not_allowed'];
        yield 'git' => ['git://git.example.test/acme/project.git', 'refs/heads/main', 'protocol_not_allowed'];
        yield 'http' => ['http://git.example.test/acme/project.git', 'refs/heads/main', 'protocol_not_allowed'];
        yield 'https' => ['https://git.example.test/acme/project.git', 'refs/heads/main', 'protocol_not_allowed'];
        yield 'ext' => ['ext::helper %S/repository', 'refs/heads/main', 'protocol_not_allowed'];
        yield 'host' => ['git@unknown.example:acme/project.git', 'refs/heads/main', 'host_not_allowed'];
        yield 'path' => ['git@git.example.test:other/project.git', 'refs/heads/main', 'path_not_allowed'];
        yield 'ref' => ['git@git.example.test:acme/project.git', 'refs/heads/../../main', 'ref_not_allowed'];
    }

    public function test_a_non_pinned_known_hosts_file_is_rejected(): void
    {
        file_put_contents($this->knownHosts, 'git.example.test ssh-ed25519 '.base64_encode(random_bytes(48))."\n");

        $this->expectException(GitRemoteRejected::class);
        $this->expectExceptionMessage('host_key_not_pinned');
        $this->policy()->validate('git@git.example.test:acme/project.git', 'refs/heads/main', $this->knownHosts);
    }

    public function test_execution_is_bound_to_the_projects_exact_registered_fingerprint(): void
    {
        $other = 'SHA256:'.rtrim(base64_encode(hash('sha256', random_bytes(48), true)), '=');
        $policy = new GitRemotePolicy(new GitConfiguration(
            'git',
            '/usr/bin/ssh',
            '/usr/local/bin:/usr/bin:/bin',
            __FILE__,
            dirname(__DIR__, 3),
            dirname(__DIR__, 3),
            __FILE__,
            dirname(__DIR__, 3),
            ['git.example.test'],
            ['acme/project.git'],
            ['refs/heads/*'],
            ['git.example.test' => [$this->fingerprint, $other]],
        ), new KnownHostsVerifier);

        self::assertSame('git.example.test', $policy->validate(
            'git@git.example.test:acme/project.git',
            'refs/heads/main',
            $this->knownHosts,
            $this->fingerprint,
        )->host);

        try {
            $policy->validate(
                'git@git.example.test:acme/project.git',
                'refs/heads/main',
                $this->knownHosts,
                $other,
            );
            self::fail('Execution accepted a known-host key that differed from the project binding.');
        } catch (GitRemoteRejected $exception) {
            self::assertSame('host_key_not_pinned', $exception->reason);
        }
    }

    public function test_registration_validates_the_same_remote_contract_against_digest_bytes(): void
    {
        $validated = $this->policy()->validateForRegistration(
            'git@git.example.test:acme/project.git',
            'refs/heads/main',
            strtolower(substr($this->fingerprint, 0, 6)).substr($this->fingerprint, 6).'=',
        );

        self::assertSame('git.example.test', $validated->host);
        self::assertSame('acme/project.git', $validated->path);
    }

    public function test_registration_rejects_format_before_inspecting_configured_digests(): void
    {
        $policy = new GitRemotePolicy(new GitConfiguration(
            'git',
            '/usr/bin/ssh',
            '/usr/local/bin:/usr/bin:/bin',
            __FILE__,
            dirname(__DIR__, 3),
            dirname(__DIR__, 3),
            __FILE__,
            dirname(__DIR__, 3),
            ['git.example.test'],
            ['acme/project.git'],
            ['refs/heads/*'],
            ['git.example.test' => ['not-a-fingerprint']],
        ), new KnownHostsVerifier);

        try {
            $policy->validateForRegistration(
                'git@git.example.test:acme/project.git',
                'refs/heads/main',
                'SHA256:not-base64',
            );
            self::fail('The malformed fingerprint was unexpectedly accepted.');
        } catch (GitRemoteRejected $exception) {
            self::assertSame('format_error', $exception->reason);
        }
    }

    private function policy(): GitRemotePolicy
    {
        return new GitRemotePolicy(new GitConfiguration(
            'git',
            '/usr/bin/ssh',
            '/usr/local/bin:/usr/bin:/bin',
            __FILE__,
            dirname(__DIR__, 3),
            dirname(__DIR__, 3),
            __FILE__,
            dirname(__DIR__, 3),
            ['git.example.test'],
            ['acme/project.git'],
            ['refs/heads/*'],
            ['git.example.test' => [$this->fingerprint]],
        ), new KnownHostsVerifier);
    }
}
