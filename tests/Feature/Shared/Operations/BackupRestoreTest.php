<?php

namespace Tests\Feature\Shared\Operations;

use App\AI6\Auth\Models\LoginConfirmation;
use App\AI6\Auth\Models\TotpCredential;
use App\AI6\Auth\Models\UserSession;
use App\AI6\Auth\TotpSecretCipher;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Shared\Operations\BackupManifest;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

final class BackupRestoreTest extends OperationsTestCase
{
    /** TC-01: real VACUUM snapshot, full inventory, secret exclusions and modes. */
    public function test_backup_contains_a_standalone_snapshot_and_only_the_selected_payloads(): void
    {
        [$run, , $user] = $this->observedRun('AI6-049-BACKUP');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'backup payload');
        $secret = $this->createConfirmedTotp($user);
        $this->seedLogin($user);
        $this->quiesceRun($run);
        // These adjacent private stores must never be traversed.
        $excluded = bin2hex(random_bytes(32));
        file_put_contents($this->root.'/managed/.env', $excluded);
        mkdir($this->root.'/managed/provider-store');
        file_put_contents($this->root.'/managed/provider-store/auth.json', $excluded);

        $this->createBackup();

        $manifest = $this->manifest();
        self::assertSame(BackupManifest::SCHEMA, $manifest['schema']);
        self::assertSame('testing', $manifest['environment']);
        self::assertSame('2026-09-26T12:00:00.000000Z', $manifest['created_at']);
        self::assertSame(DB::table('migrations')->orderBy('migration')->pluck('migration')->all(), $manifest['migrations']);
        $ring = $this->app->make(RedactionKeyring::class);
        self::assertSame([['id' => 'backup-key-v1', 'version' => 1,
            'check_hmac' => hash_hmac('sha256', BackupManifest::KEY_CHECK, $ring->activeKey())]], $manifest['redaction_keys']);
        $paths = array_column($manifest['files'], 'path');
        self::assertContains('database.sqlite', $paths);
        self::assertContains('managed/known_hosts', $paths);
        self::assertContains('managed/deploy-keys/project.key', $paths);
        self::assertContains('run-artifacts/'.$run->id.'/'.basename((string) $artifact->storage_reference), $paths);
        foreach ($manifest['files'] as $entry) {
            $path = $this->backup.'/'.$entry['path'];
            self::assertSame(hash_file('sha256', $path), $entry['sha256']);
            self::assertSame(filesize($path), $entry['size']);
        }
        foreach ((new Filesystem)->allFiles($this->backup, true) as $file) {
            $bytes = $file->getContents();
            foreach ([(string) config('app.key'), $ring->activeKey(), $excluded, $secret] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $bytes);
            }
        }
        $snapshot = $this->snapshot();
        self::assertSame(['ok'], $snapshot->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(1, $snapshot->query('SELECT count(*) FROM sessions')->fetchColumn());
        self::assertSame($secret, $this->app->make(TotpSecretCipher::class)->decrypt($snapshot->query('SELECT encrypted_secret FROM totp_credentials')->fetchColumn()));
        unset($snapshot);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0700, fileperms($this->backup) & 0777);
            self::assertSame(0600, fileperms($this->backup.'/managed/deploy-keys/project.key') & 0777);
        }
    }

    /** TC-01 */
    #[DataProvider('nonQuiescentStates')]
    public function test_backup_refuses_non_quiescent_state_before_creating_the_target(string $state): void
    {
        if ($state === 'run') {
            $this->observedRun('AI6-049-ACTIVE');
        } else {
            $actor = $this->createUser(['is_global_admin' => true]);
            $project = $this->provisionedProject($actor);
            $operation = $this->app->make(QueueTicketReadModelRefresh::class)->handle($actor, $project, 'tickets/AI6-049.md', (string) Str::uuid());
            if ($state === 'claimed') {
                $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('a', 32));
            }
        }
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->backup]));
        self::assertStringContainsString('backup_state_not_quiescent', Artisan::output());
        self::assertDirectoryDoesNotExist($this->backup);
    }

    /** @return iterable<string, array{string}> */
    public static function nonQuiescentStates(): iterable
    {
        yield 'active run' => ['run'];
        yield 'queued operation' => ['queued'];
        yield 'claimed operation' => ['claimed'];
    }

    /** TC-01 */
    public function test_existing_and_nested_targets_are_refused_without_payload_files(): void
    {
        mkdir($this->backup);
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->backup]));
        self::assertStringContainsString('backup_target_exists', Artisan::output());
        self::assertSame(['.', '..'], scandir($this->backup));
        $target = $this->root.'/run-artifacts/nested';
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $target]));
        self::assertStringContainsString('backup_path_overlap', Artisan::output());
        self::assertDirectoryDoesNotExist($target);
    }

    /** TC-01: the last-file commit marker is absent after an actual copy error. */
    #[DataProvider('failedBackupTargets')]
    public function test_a_failed_backup_copy_never_publishes_a_manifest(bool $defaultTarget, bool $sensitive): void
    {
        if ($sensitive) {
            $this->backup .= '-password=private-fixture';
        }
        $this->app->instance(Filesystem::class, new class extends Filesystem
        {
            public function copy($path, $target): bool
            {
                return false;
            }
        });
        self::assertSame(1, Artisan::call('ai6:backup', $defaultTarget ? [] : ['target' => $this->backup]));
        $output = Artisan::output();
        if ($defaultTarget) {
            $targets = glob($this->root.'/managed/backups/*');
            self::assertIsArray($targets);
            self::assertCount(1, $targets);
            $this->backup = $targets[0];
        }
        self::assertStringContainsString('backup_write_failed', $output);
        self::assertStringContainsString('Backup unvollständig', $output);
        $safe = $this->app->make(Redactor::class)->redact($this->backup, new RedactionContext('instance', null, 'backup-command'))->text;
        self::assertStringContainsString('Ziel: '.$safe, $output);
        self::assertStringNotContainsString('private-fixture', $output);
        self::assertFileExists($this->backup.'/database.sqlite');
        self::assertFileDoesNotExist($this->backup.'/manifest.json');
        $this->restoreBackup(1, 'restore_manifest_missing');
    }

    /** @return array<string, array{bool, bool}> */
    public static function failedBackupTargets(): array
    {
        return ['explicit' => [false, false], 'default' => [true, false], 'sensitive' => [false, true]];
    }

    /** TC-02: every rejection leaves all live bytes and auth rows unchanged. */
    #[DataProvider('invalidBackups')]
    public function test_restore_validates_every_input_before_any_live_change(string $fault, string $reason): void
    {
        [$run, , $user] = $this->observedRun('AI6-049-REJECT');
        $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'untampered artifact');
        $this->createConfirmedTotp($user);
        $this->seedLogin($user);
        $this->quiesceRun($run);
        $this->createBackup();
        $manifest = $this->manifest();
        $artifact = array_values(array_filter($manifest['files'], static fn (array $entry): bool => str_starts_with($entry['path'], 'run-artifacts/')))[0]['path'];
        switch ($fault) {
            case 'checksum':
                file_put_contents($this->backup.'/'.$artifact, 'tampered');
                break;
            case 'parent':
                $manifest['files'][0]['path'] = '../outside';
                break;
            case 'absolute':
                $manifest['files'][0]['path'] = '/outside';
                break;
            case 'duplicate':
                $manifest['files'][] = $manifest['files'][0];
                break;
            case 'schema':
                $manifest['schema'] = 'other';
                break;
            case 'extra':
                file_put_contents($this->backup.'/extra', 'unlisted');
                break;
            case 'corrupt':
                file_put_contents($this->backup.'/database.sqlite', random_bytes(256));
                break;
            case 'migration':
                $snapshot = $this->snapshot();
                $snapshot->exec('DELETE FROM migrations WHERE id = (SELECT max(id) FROM migrations)');
                unset($snapshot);
                break;
            case 'totp':
                $snapshot = $this->snapshot();
                $snapshot->prepare('UPDATE totp_credentials SET encrypted_secret = ?')->execute([(new Encrypter(random_bytes(32), 'AES-256-CBC'))->encrypt(bin2hex(random_bytes(16)), false)]);
                unset($snapshot);
                break;
            case 'missing-key':
                $this->bindOperationsKeyring(new RedactionKeyring('other-key', ['other-key' => ['version' => 2, 'key' => random_bytes(32)]]));
                break;
            case 'key-bytes':
            case 'key-version':
                $ring = $this->app->make(RedactionKeyring::class);
                $this->bindOperationsKeyring(new RedactionKeyring('backup-key-v1', ['backup-key-v1' => [
                    'version' => $fault === 'key-version' ? 2 : 1,
                    'key' => $fault === 'key-bytes' ? random_bytes(32) : $ring->activeKey(),
                ]]));
                break;
            case 'omitted-proof':
                $manifest['redaction_keys'] = [];
                break;
        }
        $this->writeManifest($manifest);
        if (in_array($fault, ['corrupt', 'migration', 'totp'], true)) {
            $this->rehashSnapshot();
        }
        file_put_contents($this->root.'/managed/credentials/deploy-keys/project.key', random_bytes(64));
        $before = $this->liveBytes();
        $sessions = UserSession::query()->get()->toArray();
        $challenges = LoginConfirmation::query()->get()->toArray();

        $this->restoreBackup(1, $reason);

        self::assertSame($before, $this->liveBytes());
        self::assertSame($sessions, UserSession::query()->get()->toArray());
        self::assertSame($challenges, LoginConfirmation::query()->get()->toArray());
        self::assertSame([], glob($this->root.'/*.pre-restore-*'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBackups(): iterable
    {
        yield 'tampered file' => ['checksum', 'restore_checksum_mismatch'];
        yield 'parent traversal' => ['parent', 'restore_path_invalid'];
        yield 'absolute path' => ['absolute', 'restore_path_invalid'];
        yield 'duplicate path' => ['duplicate', 'restore_duplicate_path'];
        yield 'wrong manifest schema' => ['schema', 'restore_manifest_invalid'];
        yield 'unlisted payload' => ['extra', 'restore_inventory_mismatch'];
        yield 'corrupt SQLite with matching checksum' => ['corrupt', 'restore_snapshot_invalid'];
        yield 'different migrations with matching checksum' => ['migration', 'restore_schema_mismatch'];
        yield 'other encryption key' => ['totp', 'restore_totp_undecryptable'];
        yield 'missing redaction key' => ['missing-key', 'restore_redaction_key_missing'];
        yield 'reused id with new material' => ['key-bytes', 'restore_redaction_key_mismatch'];
        yield 'reused id with new version' => ['key-version', 'restore_redaction_key_mismatch'];
        yield 'omitted key proof' => ['omitted-proof', 'restore_redaction_key_mismatch'];
    }

    /** TC-03 */
    public function test_successful_restore_recovers_data_revokes_the_real_client_and_removes_previous_copies(): void
    {
        [$run, $project, $user] = $this->observedRun('AI6-049-SUCCESS');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'restored payload');
        $secret = $this->createConfirmedTotp($user);
        $session = $this->seedLogin($user);
        $this->quiesceRun($run);
        $cookie = config('session.cookie');
        self::assertIsString($cookie);
        Auth::forgetGuards();
        $this->withCookie($cookie, $session)->get(route('projects.runs.show', [$project, $run->id]))->assertOk();
        $this->createBackup();
        $oldKey = file_get_contents($this->root.'/managed/credentials/deploy-keys/project.key');
        file_put_contents($this->root.'/managed/credentials/deploy-keys/project.key', random_bytes(64));
        file_put_contents((string) $this->observedArtifactPath($artifact), 'live changes');

        $this->restoreBackup();

        self::assertSame($secret, $this->app->make(TotpSecretCipher::class)->decrypt(TotpCredential::query()->firstOrFail()->encrypted_secret));
        self::assertSame(0, UserSession::query()->count());
        self::assertSame(0, LoginConfirmation::query()->count());
        self::assertSame('restored payload', $this->app->make(RunArtifactStore::class)->bytes($artifact->fresh()));
        self::assertSame($oldKey, file_get_contents($this->root.'/managed/credentials/deploy-keys/project.key'));
        foreach ((new Filesystem)->allFiles($this->root, true) as $file) {
            self::assertStringNotContainsString('.pre-restore-', $file->getPathname());
        }
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame(0600, fileperms($this->root.'/managed/credentials/deploy-keys/project.key') & 0777);
        }
        Auth::forgetGuards();
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->withCookie($cookie, $session)->get(route('projects.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** TC-02: offline means both the restored and the current instance. */
    public function test_restore_refuses_an_active_live_run_without_changing_it(): void
    {
        $this->createBackup();
        $this->observedRun('AI6-049-LIVE');
        $before = $this->liveBytes();
        $this->restoreBackup(1, 'restore_state_not_quiescent');
        self::assertSame($before, $this->liveBytes());
    }

    public function test_restore_can_populate_new_empty_paths_without_an_existing_database(): void
    {
        [$run, , $user] = $this->observedRun('AI6-049-FRESH');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'fresh instance payload');
        $secret = $this->createConfirmedTotp($user);
        $this->seedLogin($user);
        $this->quiesceRun($run);
        $this->createBackup();
        // JSON object property order has no semantic meaning.
        $manifest = $this->manifest();
        $manifest['redaction_keys'][0] = array_reverse($manifest['redaction_keys'][0], true);
        $this->writeManifest($manifest);
        $key = file_get_contents($this->root.'/managed/credentials/deploy-keys/project.key');
        DB::purge();
        $fresh = $this->root.'/fresh';
        config([
            'database.connections.sqlite.database' => $fresh.'/database.sqlite',
            'ai6.run_artifacts.root' => $fresh.'/run-artifacts',
            'ai6.control_operations.managed_root' => $fresh.'/managed',
            'ai6.control_operations.key_root' => $fresh.'/managed/credentials/deploy-keys',
            'ai6.control_operations.known_hosts_file' => $fresh.'/managed/credentials/known_hosts',
        ]);
        foreach ([RunArtifactRoot::class, RunArtifactStore::class, ControlOperationConfiguration::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        self::assertDirectoryDoesNotExist($fresh);

        $this->restoreBackup();

        self::assertSame($secret, $this->app->make(TotpSecretCipher::class)->decrypt(TotpCredential::query()->firstOrFail()->encrypted_secret));
        self::assertSame('fresh instance payload', $this->app->make(RunArtifactStore::class)->bytes($artifact->fresh()));
        self::assertSame($key, file_get_contents($fresh.'/managed/credentials/deploy-keys/project.key'));
        self::assertSame(0, UserSession::query()->count());
        self::assertSame(0, LoginConfirmation::query()->count());
    }

    /** TC-04: an injected failure inside the actual switch, not pre-validation. */
    public function test_a_failed_artifact_switch_retains_previous_copies_and_reports_each_actual_state(): void
    {
        $this->createBackup();
        $this->app->instance(Filesystem::class, new class($this->root.'/run-artifacts') extends Filesystem
        {
            public function __construct(private string $artifactPath) {}

            public function move($path, $target): bool
            {
                return $path === $this->artifactPath ? false : parent::move($path, $target);
            }
        });

        $this->restoreBackup(1, 'Restore unvollständig: restore_switch_failed');

        $output = $this->lastOutput;
        self::assertStringContainsString('database.sqlite: alter Stand in Sicherung; Ziel fehlt', $output);
        self::assertStringContainsString('run-artifacts: alter Stand', $output);
        self::assertStringContainsString('managed/deploy-keys: alter Stand', $output);
        self::assertStringContainsString('managed/known_hosts: alter Stand', $output);
        self::assertFileDoesNotExist($this->databaseFile);
        self::assertCount(1, glob($this->databaseFile.'.pre-restore-*'));
        self::assertDirectoryExists($this->root.'/run-artifacts');
    }

    public function test_an_unwritable_managed_parent_is_rejected_before_any_path_is_moved(): void
    {
        $this->createBackup();
        $before = $this->liveBytes();
        $this->app->instance(Filesystem::class, new class($this->root.'/managed/credentials') extends Filesystem
        {
            public function __construct(private string $managedRoot) {}

            public function isWritable($path): bool
            {
                return $path !== $this->managedRoot && parent::isWritable($path);
            }
        });

        $this->restoreBackup(1, 'restore_target_not_writable');

        self::assertSame($before, $this->liveBytes());
        self::assertSame([], glob($this->root.'/*.pre-restore-*'));
        self::assertStringNotContainsString('Restore unvollständig', $this->lastOutput);
    }
}
