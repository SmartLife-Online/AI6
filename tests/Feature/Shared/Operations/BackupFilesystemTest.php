<?php

namespace Tests\Feature\Shared\Operations;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class BackupFilesystemTest extends OperationsTestCase
{
    public function test_default_backup_and_restore_use_the_private_managed_subtree(): void
    {
        self::assertSame(0, Artisan::call('ai6:backup'));
        $targets = glob($this->root.'/managed/backups/*');
        self::assertIsArray($targets);
        self::assertCount(1, $targets);
        self::assertFileExists($targets[0].'/manifest.json');
        self::assertFileExists($targets[0].'/managed/deploy-keys/project.key');
        self::assertSame(0, Artisan::call('ai6:restore', ['source' => $targets[0]]), Artisan::output());
        self::assertFileExists($targets[0].'/manifest.json');
    }

    public function test_other_managed_subtrees_remain_forbidden_backup_locations(): void
    {
        foreach (['effect-locks/backup', '.control-staging/backup', 'credentials/backup', 'clone/backup'] as $path) {
            self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->root.'/managed/'.$path]));
            self::assertStringContainsString('backup_path_overlap', Artisan::output());
            self::assertDirectoryDoesNotExist($this->root.'/managed/'.$path);
        }
    }

    public function test_shared_application_storage_is_rejected_before_private_keys_are_copied(): void
    {
        $storage = $this->app->storagePath();
        $this->app->useStoragePath($this->root.'/storage');
        try {
            self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->root.'/storage/explicit']));
            self::assertStringContainsString('backup_shared_storage_path_overlap', Artisan::output());
            self::assertDirectoryDoesNotExist($this->root.'/storage');
        } finally {
            $this->app->useStoragePath($storage);
        }
    }

    public function test_shared_database_volume_cannot_receive_private_key_backups(): void
    {
        $target = dirname($this->databaseFile).'/backup';
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $target]));
        self::assertStringContainsString('backup_shared_database_path_overlap', Artisan::output());
        self::assertDirectoryDoesNotExist($target);
    }

    /** TC-01/TC-02: Linux filesystem evidence is separate from Windows tests. */
    public function test_backup_and_restore_refuse_symlinks_even_when_unlisted_in_the_manifest(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('Symlink and POSIX mode evidence requires the Linux runtime.');
        }
        $link = $this->root.'/run-artifacts/link';
        self::assertTrue(symlink($this->root.'/managed/credentials/known_hosts', $link));
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->backup]));
        self::assertStringContainsString('backup_unsupported_entry', Artisan::output());
        self::assertDirectoryDoesNotExist($this->backup);
        self::assertTrue(unlink($link));
        $this->createBackup();
        self::assertTrue(symlink($this->root.'/managed/credentials/known_hosts', $this->backup.'/unlisted-link'));
        $before = $this->liveBytes();
        $this->restoreBackup(1, 'restore_unsupported_entry');
        self::assertSame($before, $this->liveBytes());
    }

    public function test_commands_refuse_the_wrong_role_and_memory_databases(): void
    {
        config(['ai6.runtime_role' => 'app']);
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->backup]));
        self::assertStringContainsString('backup_worker_required', Artisan::output());
        $this->restoreBackup(1, 'restore_worker_required');
        config(['ai6.runtime_role' => 'worker', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge();
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->backup]));
        self::assertStringContainsString('backup_database_path_invalid', Artisan::output());
        $this->restoreBackup(1, 'restore_database_path_invalid');
        config(['database.default' => 'mysql']);
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->backup]));
        self::assertStringContainsString('backup_driver_unsupported', Artisan::output());
        $this->restoreBackup(1, 'restore_driver_unsupported');
    }
}
