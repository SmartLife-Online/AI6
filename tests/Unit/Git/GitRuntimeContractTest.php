<?php

namespace Tests\Unit\Git;

use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitConfigurationFactory;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessConfigurationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitRuntimeContractTest extends TestCase
{
    public function test_process_and_git_configuration_are_strictly_resolved(): void
    {
        $process = (new ProcessConfigurationFactory)->inspect([
            'timeout_seconds' => '300',
            'output_limit_bytes' => '1048576',
            'cancel_grace_milliseconds' => '2000',
            'wrapper_ready_timeout_seconds' => '30',
            'wrapper_script' => '/opt/ai6/wrapper',
            'shell_binary' => '/usr/bin/dash',
            'setsid_binary' => '/usr/bin/setsid',
            'process_group_kill_binary' => '/usr/bin/kill',
            'lock_directory' => '/var/lib/ai6/effect-locks',
            'lock_object_count' => '64',
            'lock_wait_milliseconds' => '30000',
            'lock_owner_uid' => '0',
        ]);
        self::assertInstanceOf(ProcessConfiguration::class, $process);
        self::assertSame(64, $process->lockObjectCount);

        $invalid = (new ProcessConfigurationFactory)->inspect([
            'timeout_seconds' => 'unbounded',
        ]);
        self::assertInstanceOf(ConfigurationViolation::class, $invalid);
        self::assertStringContainsString('AI6_PROCESS_TIMEOUT_SECONDS', $invalid->message);

        $fingerprint = 'SHA256:'.rtrim(base64_encode(random_bytes(32)), '=');
        $git = (new GitConfigurationFactory)->inspect([
            'binary' => '/usr/bin/git',
            'ssh_binary' => '/usr/bin/ssh',
            'executable_path' => '/usr/local/bin:/usr/bin:/bin',
            'ssh_wrapper' => '/opt/ai6/bin/ai6-git-ssh.sh',
            'execution_home' => '/var/lib/ai6/git-home',
            'xdg_config_home' => '/var/lib/ai6/git-home/xdg',
            'global_config' => '/opt/ai6/etc/gitconfig',
            'hooks_path' => '/opt/ai6/etc/git-hooks',
            'allowed_hosts' => 'git.example.test',
            'allowed_remote_paths' => 'acme/project.git',
            'allowed_ref_patterns' => 'refs/heads/*',
            'pinned_host_keys' => 'git.example.test='.$fingerprint,
        ]);
        self::assertInstanceOf(GitConfiguration::class, $git);
        self::assertSame(['git.example.test'], $git->allowedHosts);

        $unpinned = (new GitConfigurationFactory)->inspect([
            'binary' => '/usr/bin/git',
            'ssh_binary' => '/usr/bin/ssh',
            'executable_path' => '/usr/local/bin:/usr/bin:/bin',
            'ssh_wrapper' => '/opt/ai6/bin/ai6-git-ssh.sh',
            'execution_home' => '/var/lib/ai6/git-home',
            'xdg_config_home' => '/var/lib/ai6/git-home/xdg',
            'global_config' => '/opt/ai6/etc/gitconfig',
            'hooks_path' => '/opt/ai6/etc/git-hooks',
            'allowed_hosts' => 'git.example.test',
            'allowed_remote_paths' => 'acme/project.git',
            'allowed_ref_patterns' => 'refs/heads/*',
            'pinned_host_keys' => '',
        ]);
        self::assertInstanceOf(ConfigurationViolation::class, $unpinned);
    }

    public function test_runtime_image_contains_git_ssh_and_the_isolated_execution_paths(): void
    {
        $root = dirname(__DIR__, 3);
        $dockerfile = file_get_contents($root.'/Dockerfile');
        self::assertIsString($dockerfile);

        foreach ([
            'git openssh-client procps util-linux',
            '/usr/bin/git /usr/bin/ssh /usr/bin/ssh-keygen /usr/bin/flock /usr/bin/stat /usr/bin/setsid /usr/bin/kill /usr/bin/dash',
            'test -x "$executable"',
            'test ! -L "$executable"',
            'AI6_GIT_EXECUTION_HOME=/var/lib/ai6/git-home',
            'AI6_GIT_XDG_CONFIG_HOME=/var/lib/ai6/git-home/xdg',
            'AI6_GIT_GLOBAL_CONFIG=/opt/ai6/etc/gitconfig',
            '/opt/ai6/etc/git-hooks',
            'chmod 0700 /var/lib/ai6/git-home',
            '/opt/ai6/bin/ai6-git-ssh.sh',
            '/opt/ai6/app/AI6/Shared/Process/control-process-wrapper.sh',
        ] as $required) {
            self::assertStringContainsString($required, $dockerfile);
        }

        $readme = file_get_contents($root.'/README.md');
        self::assertIsString($readme);
        foreach ([
            'chmod a-w app/AI6/Shared/Process/control-process-wrapper.sh',
            'chmod a+x,a-w bin/ai6-git-ssh.sh',
            'AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY=/fixtures/primary',
            'AI6_EFFECT_LOCK_SECONDARY_FIXTURE_DIRECTORY=/fixtures/secondary',
        ] as $required) {
            self::assertStringContainsString($required, $readme);
        }
    }

    public function test_posix_process_runtime_paths_must_be_canonical_non_symlink_executables_with_safe_modes(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The executable path contract requires the Linux runtime.');
        }

        $directory = sys_get_temp_dir().'/ai6-process-paths-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $wrapper = $directory.'/wrapper';
        $link = $directory.'/wrapper-link';
        $runtimeBinary = $directory.'/runtime-binary';
        file_put_contents($wrapper, "#!/usr/bin/dash\nexit 0\n");
        file_put_contents($runtimeBinary, "#!/usr/bin/dash\nexit 0\n");
        chmod($wrapper, 0555);
        chmod($runtimeBinary, 0555);
        $configuration = [
            'timeout_seconds' => '300',
            'output_limit_bytes' => '1048576',
            'cancel_grace_milliseconds' => '2000',
            'wrapper_ready_timeout_seconds' => '30',
            'wrapper_script' => realpath($wrapper),
            'shell_binary' => '/usr/bin/dash',
            'setsid_binary' => '/usr/bin/setsid',
            'process_group_kill_binary' => '/usr/bin/kill',
            'lock_directory' => '/var/lib/ai6/effect-locks',
            'lock_object_count' => '64',
            'lock_wait_milliseconds' => '30000',
            'lock_owner_uid' => '0',
        ];

        try {
            self::assertInstanceOf(ProcessConfiguration::class, (new ProcessConfigurationFactory)->inspectRuntime($configuration));

            $configuration['wrapper_script'] = '/etc/hosts';
            self::assertInstanceOf(
                ProcessConfiguration::class,
                (new ProcessConfigurationFactory)->inspectRuntime($configuration),
                'A foreign-owned, runtime-read-only 0644 wrapper must be accepted.',
            );
            $configuration['wrapper_script'] = '/usr/bin/dash';
            self::assertInstanceOf(
                ProcessConfiguration::class,
                (new ProcessConfigurationFactory)->inspectRuntime($configuration),
                'A foreign-owned, runtime-read-only 0755 wrapper must be accepted.',
            );

            symlink($wrapper, $link);
            $configuration['wrapper_script'] = $link;
            $symlink = (new ProcessConfigurationFactory)->inspectRuntime($configuration);
            self::assertInstanceOf(ConfigurationViolation::class, $symlink);
            self::assertStringContainsString('symlink', $symlink->message);

            unlink($link);
            $configuration['wrapper_script'] = $wrapper;
            chmod($wrapper, 0755);
            $mode = (new ProcessConfigurationFactory)->inspectRuntime($configuration);
            self::assertInstanceOf(ConfigurationViolation::class, $mode);
            self::assertStringContainsString('runtime identity', $mode->message);

            chmod($wrapper, 0555);
            foreach ([
                'shell_binary' => ['AI6_PROCESS_SHELL_BINARY', '/usr/bin/dash'],
                'setsid_binary' => ['AI6_PROCESS_SETSID_BINARY', '/usr/bin/setsid'],
                'process_group_kill_binary' => ['AI6_PROCESS_GROUP_KILL_BINARY', '/usr/bin/kill'],
            ] as $field => [$key, $original]) {
                $configuration[$field] = realpath($runtimeBinary);
                chmod($runtimeBinary, 0755);
                $binaryViolation = (new ProcessConfigurationFactory)->inspectRuntime($configuration);
                self::assertInstanceOf(ConfigurationViolation::class, $binaryViolation);
                self::assertStringContainsString($key, $binaryViolation->message);
                self::assertStringContainsString('runtime identity', $binaryViolation->message);
                chmod($runtimeBinary, 0555);
                $configuration[$field] = $original;
            }
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            @chmod($wrapper, 0600);
            @unlink($wrapper);
            @chmod($runtimeBinary, 0600);
            @unlink($runtimeBinary);
            @rmdir($directory);
        }
    }

    public function test_symfony_process_remains_transitive_and_direct_dependencies_are_locked(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertArrayNotHasKey('symfony/process', $composer['require']);
        self::assertSame('^8.0', $composer['require']['symfony/yaml'] ?? null);
        self::assertSame('^4.4', $composer['require']['livewire/livewire'] ?? null);

        $lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);
        $versions = [];
        foreach ($lock['packages'] as $package) {
            if (in_array($package['name'], ['symfony/process', 'symfony/yaml'], true)) {
                $versions[] = $package['version'];
            }
        }
        self::assertSame(['v7.4.13', 'v8.1.2'], $versions);

        $baseComposerProcess = new Process(['git', 'show', 'b29d802:composer.json'], $root);
        $baseLockProcess = new Process(['git', 'show', 'b29d802:composer.lock'], $root);
        $baseComposerProcess->run();
        $baseLockProcess->run();
        if (! $baseComposerProcess->isSuccessful() || ! $baseLockProcess->isSuccessful()) {
            self::markTestSkipped('The composer provenance comparison requires commit b29d802.');
        }

        $baseComposer = json_decode($baseComposerProcess->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baseComposer);
        // AI6-007 approved symfony/yaml and AI6-008 approved livewire/livewire
        // as the only direct additions on top of the M1 provenance base.
        $baseComposer['require']['symfony/yaml'] = '^8.0';
        $baseComposer['require']['livewire/livewire'] = '^4.4';
        $normalizedComposer = $composer;
        ksort($baseComposer['require']);
        ksort($normalizedComposer['require']);
        self::assertSame($baseComposer, $normalizedComposer);

        $baseLock = json_decode($baseLockProcess->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baseLock);
        $lockWithoutAdditions = $lock;
        $lockWithoutAdditions['packages'] = array_values(array_filter(
            $lockWithoutAdditions['packages'],
            static fn (array $package): bool => ! in_array($package['name'], ['symfony/yaml', 'livewire/livewire'], true),
        ));
        $baseLock['content-hash'] = $lockWithoutAdditions['content-hash'];
        self::assertSame($baseLock, $lockWithoutAdditions);
    }
}
