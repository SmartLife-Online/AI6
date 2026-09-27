<?php

namespace Tests\Unit\Shared\Runtime;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WorkerStorageProvisioningTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('The real init provisioning requires Linux with UID 0.');
        }
        $this->root = sys_get_temp_dir().'/ai6-worker-storage-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0755));
        chmod($this->root, 0755);
        chown($this->root, 0);
        chgrp($this->root, 0);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }
    }

    public function test_fresh_and_legacy_layouts_are_private_and_rerunnable_without_touching_locks(): void
    {
        mkdir($this->root.'/effect-locks', 0555);
        file_put_contents($this->root.'/effect-locks/lock-0001', '');
        chmod($this->root.'/effect-locks/lock-0001', 0444);
        $lock = lstat($this->root.'/effect-locks/lock-0001');
        mkdir($this->root.'/deploy-keys', 0700);
        file_put_contents($this->root.'/deploy-keys/key', random_bytes(64));
        chmod($this->root.'/deploy-keys/key', 0600);
        file_put_contents($this->root.'/known_hosts', 'host fixture');
        $digest = hash_file('sha256', $this->root.'/deploy-keys/key');
        $this->runProvisioning(mode: '--relocate-credentials')->mustRun();
        $this->runProvisioning(mode: '--relocate-credentials')->mustRun();
        $this->runProvisioning()->mustRun();
        self::assertFileDoesNotExist($this->root.'/known_hosts');
        self::assertDirectoryDoesNotExist($this->root.'/deploy-keys');
        self::assertSame($digest, hash_file('sha256', $this->root.'/credentials/deploy-keys/key'));
        foreach (['credentials', 'credentials/deploy-keys', 'backups'] as $directory) {
            self::assertSame(10001, fileowner($this->root.'/'.$directory));
            self::assertSame(0700, fileperms($this->root.'/'.$directory) & 0777);
        }
        self::assertSame(0600, fileperms($this->root.'/credentials/known_hosts') & 0777);
        self::assertSame(0, fileowner($this->root));
        self::assertSame(0755, fileperms($this->root) & 0777);
        clearstatcache();
        self::assertSame($lock, lstat($this->root.'/effect-locks/lock-0001'));

        mkdir($this->root.'/empty', 0755);
        chmod($this->root.'/empty', 0755);
        $this->runProvisioning($this->root.'/empty')->mustRun();
        self::assertDirectoryExists($this->root.'/empty/credentials/deploy-keys');
        self::assertDirectoryExists($this->root.'/empty/backups');
    }

    #[DataProvider('unsafeLayouts')]
    public function test_conflicting_or_linked_sources_are_rejected_before_mutation(string $case): void
    {
        mkdir($this->root.'/deploy-keys', 0700);
        file_put_contents($this->root.'/deploy-keys/key', 'original');
        if ($case === 'conflict') {
            mkdir($this->root.'/credentials/deploy-keys', 0700, true);
            file_put_contents($this->root.'/credentials/deploy-keys/key', 'other');
        } elseif ($case === 'parent-link') {
            symlink($this->root.'/deploy-keys', $this->root.'/credentials');
        } elseif ($case === 'nested-link') {
            symlink($this->root.'/deploy-keys/key', $this->root.'/deploy-keys/link');
        } elseif ($case === 'special-file') {
            posix_mkfifo($this->root.'/deploy-keys/pipe', 0600);
        } else {
            link($this->root.'/deploy-keys/key', $this->root.'/known_hosts');
        }
        $result = $this->runProvisioning(mode: '--relocate-credentials');
        self::assertSame(78, $result->run());
        self::assertStringContainsString('provisioning refused', $result->getErrorOutput());
        self::assertSame('original', file_get_contents($this->root.'/deploy-keys/key'));
        self::assertDirectoryDoesNotExist($this->root.'/backups');
        if ($case === 'conflict') {
            self::assertSame('other', file_get_contents($this->root.'/credentials/deploy-keys/key'));
        }
    }

    /** @return array<string, array{string}> */
    public static function unsafeLayouts(): array
    {
        return array_combine(['conflict', 'parent-link', 'nested-link', 'hard-link', 'special-file'],
            array_map(static fn (string $case): array => [$case], ['conflict', 'parent-link', 'nested-link', 'hard-link', 'special-file']));
    }

    #[DataProvider('legacyEntries')]
    public function test_normal_provisioning_refuses_legacy_entries_without_any_mutation(string $entry): void
    {
        if ($entry === 'deploy-keys') {
            mkdir($this->root.'/'.$entry, 0700);
        } elseif ($entry === 'dangling-link') {
            symlink($this->root.'/absent', $this->root.'/known_hosts');
        } else {
            file_put_contents($this->root.'/'.$entry, 'original');
        }
        $path = $this->root.'/'.($entry === 'dangling-link' ? 'known_hosts' : $entry);
        $before = lstat($path);
        foreach (['', '--check-legacy'] as $mode) {
            $result = $this->runProvisioning(mode: $mode);
            self::assertSame(78, $result->run());
            self::assertStringContainsString('worker_storage_legacy_credentials', $result->getErrorOutput());
            self::assertStringContainsString('README: Bestehende Credentials einmalig übernehmen', $result->getErrorOutput());
            self::assertDirectoryDoesNotExist($this->root.'/credentials');
            self::assertDirectoryDoesNotExist($this->root.'/backups');
            clearstatcache();
            self::assertSame($before, lstat($path));
        }
    }

    /** @return array<string, array{string}> */
    public static function legacyEntries(): array
    {
        return ['keys' => ['deploy-keys'], 'hosts' => ['known_hosts'], 'link' => ['dangling-link']];
    }

    public function test_legacy_check_leaves_a_fresh_root_absent_and_rejects_unknown_modes(): void
    {
        $root = $this->root.'/absent';
        $this->runProvisioning($root, '--check-legacy')->mustRun();
        self::assertDirectoryDoesNotExist($root);
        self::assertSame(78, $this->runProvisioning(mode: '--unknown')->run());
        self::assertSame(['.', '..'], scandir($this->root));
    }

    private function runProvisioning(?string $root = null, string $mode = ''): Process
    {
        $arguments = ['/bin/sh', dirname(__DIR__, 4).'/docker/provision-worker-storage.sh', $root ?? $this->root];
        if ($mode !== '') {
            $arguments[] = $mode;
        }

        return new Process($arguments, timeout: 10);
    }
}
