<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Shared\Doctor\DoctorCommand;
use App\AI6\Shared\Doctor\ProcessRolesDoctorCheck;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessResult;
use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityPolicyFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Agents\BuildsProviderOnboarding;
use Tests\TestCase;

final class DoctorOptionsTest extends TestCase
{
    use BuildsProviderOnboarding;

    private string $root;

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/ai6-doctor-options-'.bin2hex(random_bytes(8));
        foreach (['checker-input', 'checker-output/attestations', 'heartbeat', 'hooks'] as $path) {
            mkdir($this->root.'/'.$path, 0700, true);
        }
        file_put_contents($this->root.'/known_hosts', '');
        file_put_contents($this->root.'/global', '');
        file_put_contents($this->root.'/heartbeat/boot-id', str_repeat('a', 32));
        foreach (['AI6_HEARTBEAT_DIRECTORY' => $this->root.'/heartbeat', 'AI6_HEARTBEAT_MAX_AGE' => '60'] as $key => $value) {
            $this->previousEnvironment[$key] = getenv($key);
            putenv($key.'='.$value);
        }
        (new RuntimeHeartbeat($this->root.'/heartbeat'))->write('worker', time() - 3);
        config([
            'ai6.runtime_role' => 'worker',
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mail.example.test',
            'mail.mailers.smtp.port' => 587, 'mail.from.address' => 'ai6@example.test',
            'ai6.git.binary' => PHP_BINARY, 'ai6.git.ssh_binary' => PHP_BINARY,
            'ai6.git.global_config' => $this->root.'/global', 'ai6.git.hooks_path' => $this->root.'/hooks',
            'ai6.control_operations.managed_root' => $this->root,
            'ai6.control_operations.key_root' => $this->root.'/keys',
            'ai6.control_operations.known_hosts_file' => $this->root.'/known_hosts',
            'ai6.git.allowed_hosts' => 'git.example.test', 'ai6.git.allowed_remote_paths' => 'team/*',
            'ai6.git.pinned_host_keys' => 'git.example.test=SHA256:'.str_repeat('A', 43),
            'ai6.execution_mailboxes.checker_root' => $this->root.'/checker-input',
            'ai6.execution_mailboxes.checker_output_root' => $this->root.'/checker-output',
            'ai6.run_artifacts.root' => $this->root.'/artifacts',
        ]);
        file_put_contents($this->attestationPath(), json_encode([
            'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => str_repeat('a', 32),
            'recorded_at' => time(), 'role' => 'checker', 'input_read_only' => true,
            'output_separate' => true, 'workspace_private' => true, 'container_read_only' => true,
            'network_isolated' => true, 'apparmor_confined' => true, 'namespace_tooling' => true, 'profiles_executable' => true,
            'profile_programs' => ['php-targeted' => true],
        ], JSON_THROW_ON_ERROR));
        // A synthetic future security-review selection tests the consumer; it
        // does not release a production profile or manufacture runtime evidence.
        $profiles = config('ai6.agent_profiles');
        foreach ($profiles as &$profile) {
            $profile['capability_status'] = 'available';
        }
        unset($profile);
        $profiles['copilot-cli-review']['roles'][] = 'security_review';
        config(['ai6.agent_profiles' => $profiles, 'ai6.agent_security_review_profile' => 'copilot-cli-review']);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $this->seedProviderReports();
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_shipped_wiring_runs_every_check_once_and_reuses_security_evidence(): void
    {
        $runner = app(ControlProcessRunner::class);
        $transport = new class($runner, $this->attestationPath())
        {
            public int $calls = 0;

            public function __construct(private ControlProcessRunner $runner, private string $attestation) {}

            public function run(ProcessRequest $request): ProcessResult
            {
                self::assertManifestRequest($request);
                $this->calls++;
                // All evidence readers ran before this last ordinary check.
                // A second attestation read by --security now cannot pass.
                unlink($this->attestation);

                return $this->runner->run($request);
            }

            private static function assertManifestRequest(ProcessRequest $request): void
            {
                Assert::assertSame([PHP_BINARY, base_path().'/scripts/generate-ticket-manifest.php', '--check'], array_slice($request->command, 0, 3));
                Assert::assertSame([], $request->environment);
            }
        };
        $this->app->instance(ControlProcessRunner::class, $transport);
        $exit = Artisan::call('ai6:doctor', ['--security' => true, '--require-strict' => true]);
        $output = Artisan::output();
        self::assertSame(0, $exit, $output);
        self::assertSame(1, $transport->calls);
        foreach (['SecurityPolicy', 'Redaction-Schlüsselring', 'Checker-Laufzeit', 'Codex-CLI', 'Grok-CLI', 'GitHub-Copilot-CLI', 'Mail', 'Git', 'Retention', 'Ticketmanifest', 'Securityreview-Profil', 'Strict-Profil'] as $label) {
            self::assertSame(1, substr_count($output, $label.': OK'), $label);
        }
        self::assertStringNotContainsString('Prozessrollen:', $output);
    }

    #[DataProvider('failures')]
    public function test_security_failure_names_the_measure_and_the_existing_reason(string $failure, string $measure, string $reason): void
    {
        match ($failure) {
            'mail' => config(['mail.default' => 'array']),
            'checker' => unlink($this->attestationPath()),
            'provider' => $this->degradeProviderReport(),
            'fake' => config(['app.env' => 'production', 'ai6.agent_security_review_profile' => 'fake']),
            'unknown' => config(['ai6.agent_security_review_profile' => 'unknown']),
            'invalid' => config(['ai6.agent_security_review_profile' => 'private invalid value']),
            'not-ready' => config(['ai6.copilot.capability_evidence' => []]),
            default => throw new \InvalidArgumentException('Unknown doctor failure fixture.'),
        };
        self::assertSame(1, Artisan::call('ai6:doctor', ['--security' => true]));
        $output = Artisan::output();
        self::assertMatchesRegularExpression('/Sicherheitsmaßnahme '.preg_quote($measure, '/').': FEHLER \([^\r\n]*'.preg_quote($reason, '/').'/', $output);
        self::assertStringNotContainsString('private invalid value', $output);
    }

    public function test_security_does_not_accept_a_check_outside_its_responsible_role(): void
    {
        config(['ai6.runtime_role' => 'app']);
        self::assertSame(1, Artisan::call('ai6:doctor', ['--security' => true]));
        self::assertStringContainsString(
            'Sicherheitsmaßnahme '.SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value.': FEHLER (Mail: nicht zuständig in dieser Rolle)',
            Artisan::output(),
        );
    }

    /** @return list<array{string, string, string}> */
    public static function failures(): array
    {
        return [
            ['mail', SecurityMeasure::LOGIN_EMAIL_CONFIRMATION->value, 'mail_transport_unsupported'],
            ['checker', SecurityMeasure::REQUIRE_CHECKER_NETWORK_ISOLATION->value, 'checker_attestation_missing'],
            ['provider', SecurityMeasure::REQUIRE_AGENT_SANDBOX->value, 'Menschlicher Laufzeitnachweis fehlt'],
            ['fake', SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW->value, 'security_review_adapter_fake'],
            ['unknown', SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW->value, 'security_review_profile_unresolved'],
            ['invalid', SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW->value, 'security_review_profile_unresolved'],
            ['not-ready', SecurityMeasure::REQUIRE_LLM_PRECOMMIT_REVIEW->value, 'security_review_profile_unresolved'],
        ];
    }

    public function test_default_checks_and_require_strict_have_independent_exit_semantics(): void
    {
        self::assertSame(0, Artisan::call('ai6:doctor'));
        $output = Artisan::output();
        self::assertSame(10, substr_count($output, ': OK'));
        self::assertStringNotContainsString('Securityreview-Profil:', $output);
        self::assertStringNotContainsString('Prozessrollen:', $output);

        config(['ai6.security.profile' => 'development', 'ai6.security.acknowledge_reduced_mode' => true]);
        $this->app->instance(SecurityPolicy::class, app(SecurityPolicyFactory::class)->fromConfiguredValues());
        // Re-register the command with the newly resolved policy in this fixture.
        $this->app->forgetInstance(DoctorCommand::class);
        Artisan::registerCommand(app(DoctorCommand::class));
        self::assertSame(1, Artisan::call('ai6:doctor', ['--require-strict' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('Profil: development', $output);
        self::assertStringContainsString('Deaktivierte Maßnahmen: '.SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS->value, $output);
        self::assertStringContainsString('Ticketmanifest: OK', $output);
    }

    public function test_process_roles_report_liveness_and_real_role_commands(): void
    {
        $recordedAt = time() - 11;
        config(['ai6.provider_onboarding.presence_max_age_seconds' => 60]);
        file_put_contents($this->onboardingRoot.'/presence/heartbeat.json', json_encode(['boot_id' => str_repeat('a', 32), 'recorded_at' => $recordedAt], JSON_THROW_ON_ERROR));
        // TC-05 observes role evidence without launching a native process. The
        // real manifest transport is exercised separately by the wiring test.
        $this->app->instance(ControlProcessRunner::class, new class($this->attestationPath())
        {
            public function __construct(private string $attestation) {}

            public function run(ProcessRequest $request): ProcessResult
            {
                Assert::assertSame([PHP_BINARY, base_path().'/scripts/generate-ticket-manifest.php', '--check'], array_slice($request->command, 0, 3));
                Assert::assertSame([], $request->environment);
                unlink($this->attestation);

                return new ProcessResult(ProcessOutcome::SUCCEEDED, 0, '', '', 0);
            }
        });
        $startedAt = time();
        self::assertSame(0, Artisan::call('ai6:doctor', ['--all-processes' => true]));
        $output = Artisan::output();
        self::assertStringContainsString('Prozessrollen: OK', $output);
        self::assertMatchesRegularExpression('/Heartbeat: OK \(Alter [0-9]+s\)/', $output);
        self::assertSame(1, preg_match('/Agentpräsenz: OK \(Lebendigkeit; Alter ([0-9]+)s\)/', $output, $presence));
        self::assertGreaterThanOrEqual($startedAt - $recordedAt, (int) $presence[1]);
        self::assertLessThanOrEqual(time() - $recordedAt, (int) $presence[1]);
        self::assertSame(2, substr_count($output, 'UNGEPRÜFT'));
        self::assertStringContainsString('Rolle scheduler: UNGEPRÜFT (docker compose exec scheduler php artisan ai6:runtime-health --role=scheduler)', $output);
        self::assertStringContainsString('Rolle app: UNGEPRÜFT (docker compose exec app /opt/ai6/docker/healthcheck.sh app)', $output);

        file_put_contents($this->onboardingRoot.'/presence/heartbeat.json', json_encode(['boot_id' => str_repeat('a', 32), 'recorded_at' => time() - 600], JSON_THROW_ON_ERROR));
        $result = app(ProcessRolesDoctorCheck::class)->run();
        self::assertFalse($result->passed);
        self::assertStringContainsString('Rolle agent', $result->details['Agentpräsenz']);
    }

    /** @return list<array{string}> */
    public static function presenceFailures(): array
    {
        return [['missing'], ['invalid_json'], ['future'], ['foreign_boot']];
    }

    #[DataProvider('presenceFailures')]
    public function test_missing_or_invalid_agent_presence_remains_a_value_free_failure(string $failure): void
    {
        $path = $this->onboardingRoot.'/presence/heartbeat.json';
        match ($failure) {
            'missing' => unlink($path),
            'invalid_json' => file_put_contents($path, 'private-invalid-pulse'),
            'future' => file_put_contents($path, json_encode(['boot_id' => str_repeat('a', 32), 'recorded_at' => time() + 600], JSON_THROW_ON_ERROR)),
            'foreign_boot' => file_put_contents($this->onboardingRoot.'/presence/boot-id', str_repeat('b', 32)),
            default => throw new \LogicException('Unknown agent presence fixture.'),
        };
        $result = app(ProcessRolesDoctorCheck::class)->run();
        self::assertFalse($result->passed);
        self::assertSame('FEHLER (Rolle agent)', $result->details['Agentpräsenz']);
        self::assertStringNotContainsString('private-invalid-pulse', implode(' ', $result->details));
    }

    #[DataProvider('heartbeatFailures')]
    public function test_missing_stale_and_invalid_own_heartbeats_fail_without_raw_role_values(string $failure): void
    {
        match ($failure) {
            'missing' => unlink($this->root.'/heartbeat/heartbeat.json'),
            'stale' => (new RuntimeHeartbeat($this->root.'/heartbeat'))->write('worker', time() - 600),
            'boot' => file_put_contents($this->root.'/heartbeat/boot-id', str_repeat('b', 32)),
            'role' => config(['ai6.runtime_role' => 'private-invalid-role']),
            'limit' => putenv('AI6_HEARTBEAT_MAX_AGE=invalid'),
            default => throw new \InvalidArgumentException('Unknown heartbeat fixture.'),
        };
        $result = app(ProcessRolesDoctorCheck::class)->run();
        self::assertFalse($result->passed);
        self::assertStringContainsString('FEHLER', $result->details['Heartbeat']);
        self::assertStringContainsString('OK', $result->details['Agentpräsenz']);
        self::assertStringNotContainsString('private-invalid-role', implode(' ', $result->details));
    }

    /** @return list<array{string}> */
    public static function heartbeatFailures(): array
    {
        return [['missing'], ['stale'], ['boot'], ['role'], ['limit']];
    }

    private function attestationPath(): string
    {
        return $this->root.'/checker-output/attestations/checker.json';
    }

    private function degradeProviderReport(): void
    {
        $path = $this->onboardingRoot.'/reports/codex_cli.json';
        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach ($document['rows'] as &$row) {
            $row['status'] = 'degraded';
            $row['reason'] = 'runtime';
        }
        unset($row);
        file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
    }
}
