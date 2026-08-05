<?php

namespace Tests\Feature\Projects;

use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitRemotePolicy;
use Tests\Feature\Auth\AuthFeatureTestCase;

abstract class ProjectRegistrationTestCase extends AuthFeatureTestCase
{
    protected string $fingerprint;

    protected string $fingerprintPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fingerprintPayload = rtrim(base64_encode(random_bytes(32)), '=');
        $this->fingerprint = 'SHA256:'.$this->fingerprintPayload;
        $this->configureGitRegistration();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function registrationPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Registriertes Projekt',
            'remote' => 'git@git.example.test:acme/project.git',
            'control_branch' => 'refs/heads/main',
            'host_key_fingerprint' => $this->fingerprint,
        ], $overrides);
    }

    protected function configureGitRegistration(): void
    {
        config([
            'ai6.git.allowed_hosts' => 'git.example.test',
            'ai6.git.allowed_remote_paths' => 'acme/project.git',
            'ai6.git.allowed_ref_patterns' => 'refs/heads/*',
            'ai6.git.pinned_host_keys' => 'git.example.test='.$this->fingerprint,
        ]);
        $this->app->forgetInstance(GitConfiguration::class);
        $this->app->forgetInstance(GitRemotePolicy::class);
    }

    protected function changePayloadCase(string $payload): string
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
