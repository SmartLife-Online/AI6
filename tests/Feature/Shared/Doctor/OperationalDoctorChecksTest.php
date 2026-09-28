<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Shared\Doctor\GitDoctorCheck;
use App\AI6\Shared\Doctor\MailDoctorCheck;
use App\AI6\Shared\Doctor\RetentionDoctorCheck;
use App\AI6\Shared\Doctor\TicketManifestDoctorCheck;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OperationalDoctorChecksTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/ai6-doctor-checks-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/hooks', 0700, true);
        file_put_contents($this->root.'/global', '');
        file_put_contents($this->root.'/known_hosts', '');
        config([
            'ai6.runtime_role' => 'worker',
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'private-mail.example.test',
            'mail.mailers.smtp.port' => 587, 'mail.from.address' => 'private-from@example.test',
            'ai6.git.binary' => PHP_BINARY, 'ai6.git.ssh_binary' => PHP_BINARY,
            'ai6.git.global_config' => $this->root.'/global', 'ai6.git.hooks_path' => $this->root.'/hooks',
            'ai6.control_operations.managed_root' => $this->root,
            'ai6.control_operations.key_root' => $this->root.'/keys',
            'ai6.control_operations.known_hosts_file' => $this->root.'/known_hosts',
            'ai6.run_artifacts.root' => $this->root.'/missing/nested/artifacts',
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_worker_checks_pass_without_starting_processes_or_creating_the_artifact_root(): void
    {
        $this->app->instance(ControlProcessRunner::class, new class
        {
            public function run(): never
            {
                throw new \LogicException('Static checks must not start a process.');
            }
        });
        foreach ([MailDoctorCheck::class, GitDoctorCheck::class, RetentionDoctorCheck::class] as $class) {
            $result = app($class)->run();
            self::assertTrue($result->passed, json_encode($result->details, JSON_THROW_ON_ERROR));
        }
        self::assertDirectoryDoesNotExist($this->root.'/missing');
        config(['ai6.runtime_role' => 'app', 'mail.default' => 'unsupported', 'ai6.git.binary' => '']);
        foreach ([MailDoctorCheck::class, GitDoctorCheck::class] as $class) {
            $result = app($class)->run();
            self::assertTrue($result->passed);
            self::assertSame('nicht zuständig', $result->details['Zuständigkeit']);
        }
    }

    /** @param class-string<MailDoctorCheck|GitDoctorCheck|RetentionDoctorCheck> $class */
    #[DataProvider('invalidSettings')]
    public function test_invalid_configuration_is_named_without_its_value(string $class, string $key, mixed $value, string $reason): void
    {
        config([$key => $value]);
        $result = app($class)->run();
        self::assertFalse($result->passed);
        self::assertSame($reason, $result->details['Grund']);
        $output = implode(' ', $result->details);
        self::assertStringNotContainsString('private-', $output);
        self::assertStringNotContainsString($this->root, $output);
    }

    /** @return list<array{class-string, string, mixed, string}> */
    public static function invalidSettings(): array
    {
        return [
            [MailDoctorCheck::class, 'mail.default', 'private-transport', 'mail_transport_unsupported'],
            [MailDoctorCheck::class, 'mail.mailers.smtp.host', '', 'mail_host_missing'],
            [MailDoctorCheck::class, 'mail.mailers.smtp.port', 0, 'mail_port_invalid'],
            [MailDoctorCheck::class, 'mail.mailers.smtp.port', 65536, 'mail_port_invalid'],
            [MailDoctorCheck::class, 'mail.mailers.smtp.port', 'private-port', 'mail_port_invalid'],
            [MailDoctorCheck::class, 'mail.from.address', 'private-address', 'mail_from_invalid'],
            [GitDoctorCheck::class, 'ai6.git.binary', 'private-missing-binary', 'git_binary_unavailable'],
            [GitDoctorCheck::class, 'ai6.git.ssh_binary', 'private-missing-binary', 'ssh_binary_unavailable'],
            [GitDoctorCheck::class, 'ai6.git.global_config', 'private-missing-file', 'git_global_config_unavailable'],
            [GitDoctorCheck::class, 'ai6.git.hooks_path', 'private-missing-directory', 'git_hooks_path_unavailable'],
            [GitDoctorCheck::class, 'ai6.git.allowed_hosts', 'private-invalid-host!', 'git_configuration_invalid'],
            [GitDoctorCheck::class, 'ai6.control_operations.lease_seconds', 'private-invalid-limit', 'git_configuration_invalid'],
            [RetentionDoctorCheck::class, 'ai6.retention.artifacts.max_days', 'private-invalid-limit', 'retention_configuration_invalid'],
            [RetentionDoctorCheck::class, 'ai6.run_artifacts.root', '', 'retention_configuration_invalid'],
        ];
    }

    public function test_production_git_requires_allowlists_and_known_hosts(): void
    {
        config(['app.env' => 'production', 'ai6.git.allowed_hosts' => '', 'ai6.git.allowed_remote_paths' => '', 'ai6.git.pinned_host_keys' => '']);
        self::assertSame('git_allowlist_empty', app(GitDoctorCheck::class)->run()->details['Grund']);
        config(['ai6.git.allowed_hosts' => 'git.example.test', 'ai6.git.allowed_remote_paths' => 'team/*', 'ai6.git.pinned_host_keys' => 'git.example.test=SHA256:'.str_repeat('A', 43)]);
        self::assertTrue(app(GitDoctorCheck::class)->run()->passed);
        unlink($this->root.'/known_hosts');
        self::assertSame('known_hosts_missing', app(GitDoctorCheck::class)->run()->details['Grund']);
    }

    public function test_retention_refuses_a_regular_file_as_the_existing_ancestor(): void
    {
        config(['ai6.run_artifacts.root' => $this->root.'/global/child']);
        self::assertFalse(app(RetentionDoctorCheck::class)->run()->passed);
    }

    public function test_retention_checks_only_the_root_or_nearest_existing_ancestor(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Symlink and POSIX permission evidence requires Linux.');
        }
        mkdir($this->root.'/target/child', 0700, true);
        symlink($this->root.'/target', $this->root.'/link');
        try {
            foreach (['link', 'link/missing'] as $path) {
                config(['ai6.run_artifacts.root' => $this->root.'/'.$path]);
                self::assertFalse(app(RetentionDoctorCheck::class)->run()->passed, $path);
            }
            foreach (['link/child', 'link/child/missing'] as $path) {
                config(['ai6.run_artifacts.root' => $this->root.'/'.$path]);
                self::assertTrue(app(RetentionDoctorCheck::class)->run()->passed, $path);
            }
            chmod($this->root.'/target/child', 0500);
            if (is_writable($this->root.'/target/child')) {
                self::fail('The permission evidence must run as an unprivileged user.');
            }
            config(['ai6.run_artifacts.root' => $this->root.'/target/child/missing']);
            self::assertFalse(app(RetentionDoctorCheck::class)->run()->passed);
        } finally {
            chmod($this->root.'/target/child', 0700);
            unlink($this->root.'/link');
        }
    }

    public function test_manifest_uses_the_real_generator_and_detects_plan_drift_and_missing_sources(): void
    {
        config(['ai6.process.policies.control.working_roots' => [...config('ai6.process.policies.control.working_roots'), $this->root]]);
        $this->app->forgetInstance(ProcessPolicyRegistry::class);
        $this->app->forgetInstance(ControlProcessRunner::class);
        self::assertTrue((new TicketManifestDoctorCheck)->run()->passed);
        mkdir($this->root.'/docs');
        mkdir($this->root.'/scripts');
        foreach (['docs/AI6_IMPLEMENTATION_PLAN.md', 'docs/AI6_TICKET_MANIFEST.yaml', 'scripts/generate-ticket-manifest.php'] as $path) {
            copy(base_path($path), $this->root.'/'.$path);
        }
        $check = new TicketManifestDoctorCheck($this->root);
        self::assertTrue($check->run()->passed);
        $plan = $this->root.'/docs/AI6_IMPLEMENTATION_PLAN.md';
        file_put_contents($plan, str_replace('### AI6-036 — Installation, Doctor und Security-Release-Gate', '### AI6-036 — Kontrollierte Drift', (string) file_get_contents($plan), $count));
        self::assertSame(1, $count);
        self::assertSame(['Fehler' => 'manifest_drift', 'Exitcode' => '1'], $check->run()->details);
        unlink($this->root.'/docs/AI6_TICKET_MANIFEST.yaml');
        self::assertSame(['Fehler' => 'manifest_source_unavailable'], $check->run()->details);
    }

    public function test_manifest_reports_a_rejected_start_without_inventing_an_exit_code(): void
    {
        $this->app->instance(ControlProcessRunner::class, new class
        {
            public function run(): ProcessResult
            {
                return new ProcessResult(ProcessOutcome::START_REJECTED, null, '', '', 0.0);
            }
        });
        $result = (new TicketManifestDoctorCheck)->run();
        self::assertFalse($result->passed);
        self::assertSame([
            'Fehler' => 'manifest_drift',
            'Exitcode' => 'keiner',
            'Prozessergebnis' => 'start_rejected',
        ], $result->details);
    }
}
