<?php

namespace App\AI6\Shared\Operations;

use App\AI6\Auth\Models\LoginConfirmation;
use App\AI6\Auth\Models\UserSession;
use App\AI6\Auth\TotpSecretCipher;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationState;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunRetentionSweep;
use App\AI6\Runs\RunRetentionSweepResult;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Throwable;

/** Offline operations only: the operator must stop every persistent role first. */
final readonly class BackupSet
{
    public function __construct(
        private DatabaseManager $database,
        private ConnectionFactory $connections,
        private RunArtifactRoot $artifacts,
        private ControlOperationConfiguration $control,
        private RedactionKeyring $keyring,
        private TotpSecretCipher $totp,
        private RunRetentionSweep $retention,
        private Migrator $migrator,
        private Filesystem $filesystem,
        private Redactor $redactor,
    ) {}

    public function relocateCredentials(bool $checkOnly): int
    {
        $paths = $this->paths('credentials');
        $managed = $this->absolute($this->control->managedRoot, 'credentials');
        if ($paths['managed/deploy-keys'] !== $managed.'/credentials/deploy-keys'
            || $paths['managed/known_hosts'] !== $managed.'/credentials/known_hosts') {
            throw new BackupException('credentials_layout_mismatch');
        }

        return $this->database->connection()->transaction(function () use ($checkOnly, $managed, $paths): int {
            $this->assertQuiescent($this->database->connection(), 'credentials');
            $updates = [];
            foreach ($this->database->table('projects')->select(['id', 'project_identifier', 'deploy_key_reference'])
                ->whereNotNull('deploy_key_reference')->get() as $project) {
                if (! is_string($project->project_identifier) || preg_match('/\A[0-9a-f]{32}\z/D', $project->project_identifier) !== 1) {
                    throw new BackupException('credentials_reference_invalid');
                }
                $suffix = '/'.$project->project_identifier.'/id_ed25519';
                $old = $managed.'/deploy-keys'.$suffix;
                $new = $paths['managed/deploy-keys'].$suffix;
                if (! in_array($project->deploy_key_reference, [$old, $new], true)) {
                    throw new BackupException('credentials_reference_invalid');
                }
                $file = $checkOnly && $this->stat($old) !== false ? $old : $new;
                $file = $this->absolute($file, 'credentials');
                $stat = $this->stat($file);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000
                    || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)) {
                    throw new BackupException('credentials_key_invalid');
                }
                if (! $checkOnly && $this->stat($old) !== false) {
                    throw new BackupException('credentials_legacy_files_present');
                }
                if ($project->deploy_key_reference === $old) {
                    $updates[] = [$project->id, $old, $new];
                }
            }
            // Validate every reference first. Only the current project pointer
            // moves; immutable operations, results and audit evidence do not.
            if (! $checkOnly) {
                foreach ($updates as [$id, $old, $new]) {
                    if ($this->database->table('projects')->where('id', $id)->where('deploy_key_reference', $old)
                        ->update(['deploy_key_reference' => $new]) !== 1) {
                        throw new BackupException('credentials_reference_changed');
                    }
                }
            }

            return count($updates);
        });
    }

    public function backup(?string $target): string
    {
        $paths = $this->paths('backup');
        $target = $this->absolute($target ?? $this->control->managedRoot.'/backups/'.$this->timestamp(), 'backup');
        if ($this->stat($target) !== false) {
            throw new BackupException('backup_target_exists');
        }
        $this->assertBackupLocation($target, $paths, 'backup');
        $connection = $this->database->connection();
        $this->assertQuiescent($connection, 'backup');
        $this->assertCredentialsRelocated($connection, 'backup');
        // The app shares this volume and UID with the worker. A private mode
        // does not isolate the deploy keys once they have been copied here.
        $this->assertDisjoint($target, [$this->absolute(storage_path(), 'backup')], 'backup_shared_storage');
        $this->assertDisjoint($target, [str_replace('\\', '/', dirname($paths['database.sqlite']))], 'backup_shared_database');
        $migrations = $this->migrations($connection);
        $keys = $this->keyProofs($connection, 'backup');
        $files = [];
        $directories = [];
        foreach (array_slice($paths, 1, null, true) as $relative => $path) {
            $this->inventory($path, $relative, $files, $directories, 'backup');
        }
        // Complete source validation precedes even the creation of the target.
        $this->makeDirectory($target);
        $snapshot = $target.'/database.sqlite';
        try {
            $connection->statement('VACUUM INTO ?', [$snapshot]);
            $this->mode($snapshot, 0600);
            foreach ($directories as $directory) {
                $this->makeDirectory($target.'/'.$directory['path']);
            }
            foreach ($files as $file) {
                $this->copyFile($this->sourcePath($file['path'], $paths), $target.'/'.$file['path'], $file);
            }
            // The manifest also inventories structural, empty directories.
            if (is_dir($target.'/managed')) {
                $directories[] = ['path' => 'managed', 'mode' => 0700];
            }
            $files[] = $this->fileEntry($snapshot, 'database.sqlite', 'backup');
            usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
            usort($directories, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
            $this->directoryModes($target, $directories);
            $manifest = new BackupManifest(Date::now()->utc()->format('Y-m-d\TH:i:s.u\Z'), (string) config('app.env'), $migrations, $files, $directories, $keys);
            // A failed copy has no manifest and can never be restored.
            $bytes = $manifest->bytes();
            $this->writeExclusive($target.'/manifest.json', $bytes);
        } catch (Throwable) {
            throw new BackupException('backup_write_failed', backupTarget: $target);
        }

        return $target;
    }

    public function restore(string $source): RunRetentionSweepResult
    {
        $paths = $this->paths('restore');
        $source = $this->absolute($source, 'restore');
        $this->assertBackupLocation($source, $paths, 'restore');
        $manifest = $this->verify($source);
        // The live instance and the snapshot must both be quiescent. A fresh
        // instance may have no database yet; restore never migrates it.
        if (is_file($paths['database.sqlite']) && filesize($paths['database.sqlite']) > 0) {
            $current = $this->readDatabase($paths['database.sqlite'], false);
            try {
                $this->assertQuiescent($current, 'restore');
            } finally {
                $current->disconnect();
            }
        }
        $progress = [];
        $stamp = $this->timestamp();
        $destinations = ['database.sqlite' => $paths['database.sqlite'],
            'database.sqlite-wal' => $paths['database.sqlite'].'-wal',
            'database.sqlite-shm' => $paths['database.sqlite'].'-shm',
            ...array_slice($paths, 1, null, true)];
        foreach ($destinations as $relative => $path) {
            // Reject symlinks and unexpected live entries before moving any path.
            $this->absolute($path, 'restore');
            $files = $directories = [];
            $this->inventory($path, $relative, $files, $directories, 'restore', false);
            $this->assertWritableParent($path);
            $previous = $path.'.pre-restore-'.$stamp;
            if ($this->stat($previous) !== false) {
                throw new BackupException('restore_previous_exists');
            }
            $progress[$path] = ['label' => $relative, 'previous' => $previous, 'state' => $this->stat($path) === false ? 'fehlend (vor Restore)' : 'alter Stand'];
        }

        try {
            // Purge disconnects Laravel's cached connection before replacing SQLite.
            $this->database->purge();
            foreach ($destinations as $path) {
                if ($this->stat($path) !== false) {
                    if (! $this->filesystem->move($path, $progress[$path]['previous'])) {
                        throw new BackupException('restore_switch_failed');
                    }
                    $progress[$path]['state'] = 'alter Stand in Sicherung; Ziel fehlt';
                } elseif ($progress[$path]['state'] === 'alter Stand') {
                    $progress[$path]['state'] = 'fehlend (nach SQLite-Verbindungsabbau)';
                }
            }
            foreach ($paths as $relative => $path) {
                $progress[$path]['state'] = 'neuer Stand unvollständig';
                $this->restorePath($source, $relative, $path, $manifest);
                $progress[$path]['state'] = $this->stat($path) === false ? 'neuer Stand (Pfad fehlt im Backup)' : 'neuer Stand';
            }
            $this->database->reconnect();
            $this->database->connection()->transaction(static function (): void {
                UserSession::query()->delete();
                LoginConfirmation::query()->delete();
            });
            $result = $this->retention->sweep();
            if ($result->failed !== 0 || $result->deferred !== 0) {
                throw new BackupException('restore_retention_incomplete');
            }
            // Cleanup is deliberately after session revocation and the one sweep.
            foreach ($progress as $entry) {
                $this->removePrevious($entry['previous']);
            }

            return $result;
        } catch (Throwable $exception) {
            // SQLite may recreate its sidecars as soon as the new connection opens.
            foreach (['database.sqlite-wal', 'database.sqlite-shm'] as $sidecar) {
                $path = $destinations[$sidecar];
                if ($progress[$paths['database.sqlite']]['state'] === 'neuer Stand' && $this->stat($path) !== false) {
                    $progress[$path]['state'] = 'neuer Stand (SQLite-Laufzeitdatei)';
                }
            }
            throw new BackupException($exception instanceof BackupException ? $exception->reason : 'restore_switch_failed', $progress);
        }
    }

    private function verify(string $source): BackupManifest
    {
        if (! is_dir($source)) {
            throw new BackupException('restore_source_invalid');
        }
        $files = $directories = [];
        $this->inventory($source, '', $files, $directories, 'restore', false);
        if (! is_file($source.'/manifest.json')) {
            throw new BackupException('restore_manifest_missing');
        }
        $bytes = $this->filesystem->get($source.'/manifest.json');
        $manifest = BackupManifest::read($bytes, $this->redactor);
        $expected = array_column($manifest->files, 'path');
        $actual = array_column($files, 'path');
        $expected[] = 'manifest.json';
        sort($expected);
        sort($actual);
        $expectedDirectories = array_column($manifest->directories, 'path');
        $actualDirectories = array_values(array_diff(array_column($directories, 'path'), ['']));
        sort($expectedDirectories);
        sort($actualDirectories);
        if ($expected !== $actual || $expectedDirectories !== $actualDirectories) {
            throw new BackupException('restore_inventory_mismatch');
        }
        foreach ($manifest->files as $file) {
            $actualFile = $this->fileEntry($source.'/'.$file['path'], $file['path'], 'restore');
            if ($file['sha256'] !== $actualFile['sha256'] || $file['size'] !== $actualFile['size']) {
                throw new BackupException('restore_checksum_mismatch');
            }
        }
        $snapshot = $this->readDatabase($source.'/database.sqlite', true);
        try {
            try {
                if (array_column($snapshot->select('PRAGMA integrity_check'), 'integrity_check') !== ['ok']) {
                    throw new BackupException('restore_snapshot_invalid');
                }
                $migrations = $this->migrations($snapshot);
            } catch (Throwable) {
                throw new BackupException('restore_snapshot_invalid');
            }
            $codeMigrations = array_keys($this->migrator->getMigrationFiles(database_path('migrations')));
            sort($codeMigrations);
            if ($migrations !== $codeMigrations || $migrations !== $manifest->migrations) {
                throw new BackupException('restore_schema_mismatch');
            }
            $this->assertQuiescent($snapshot, 'restore');
            $this->assertCredentialsRelocated($snapshot, 'restore');
            try {
                foreach ($snapshot->table('totp_credentials')->pluck('encrypted_secret') as $ciphertext) {
                    if (! is_string($ciphertext)) {
                        throw new BackupException('restore_totp_undecryptable');
                    }
                    $this->totp->decrypt($ciphertext);
                }
            } catch (Throwable) {
                throw new BackupException('restore_totp_undecryptable');
            }
            $proofs = $this->keyProofs($snapshot, 'restore');
            $provided = $manifest->redactionKeys;
            usort($provided, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
            if (count($proofs) !== count($provided)) {
                throw new BackupException('restore_redaction_key_mismatch');
            }
            foreach ($proofs as $index => $proof) {
                if ($proof['id'] !== $provided[$index]['id'] || $proof['version'] !== $provided[$index]['version']
                    || ! hash_equals($proof['check_hmac'], $provided[$index]['check_hmac'])) {
                    throw new BackupException('restore_redaction_key_mismatch');
                }
            }
        } finally {
            $snapshot->disconnect();
        }

        return $manifest;
    }

    /** @return array<string, string> */
    private function paths(string $operation): array
    {
        if (config('ai6.runtime_role') !== 'worker') {
            throw new BackupException($operation.'_worker_required');
        }
        $connection = $this->database->connection();
        if ($connection->getDriverName() !== 'sqlite') {
            throw new BackupException($operation.'_driver_unsupported');
        }
        $database = $connection->getConfig('database');
        if (! is_string($database) || $database === ':memory:' || str_starts_with($database, 'file:')) {
            throw new BackupException($operation.'_database_path_invalid');
        }
        $paths = ['database.sqlite' => $database, 'run-artifacts' => $this->artifacts->path,
            'managed/deploy-keys' => $this->control->keyRoot, 'managed/known_hosts' => $this->control->knownHostsFile];
        foreach ($paths as $key => $path) {
            $paths[$key] = $this->absolute($path, $operation);
        }
        foreach ($paths as $key => $path) {
            $this->assertDisjoint($path, array_values(array_diff_key($paths, [$key => true])), $operation);
            $stat = $this->stat($path);
            $expectedType = in_array($key, ['run-artifacts', 'managed/deploy-keys'], true) ? 0040000 : 0100000;
            if ($stat !== false && ($stat['mode'] & 0170000) !== $expectedType) {
                throw new BackupException($operation.'_unsupported_entry');
            }
        }

        return $paths;
    }

    private function assertQuiescent(Connection $connection, string $operation): void
    {
        $terminalRuns = [RunState::COMPLETED->value, RunState::FAILED->value, RunState::CANCELLED->value];
        $terminalOperations = array_map(static fn (ControlOperationState $state): string => $state->value,
            array_values(array_filter(ControlOperationState::cases(), static fn (ControlOperationState $state): bool => $state->terminal())));
        foreach (['runs' => $terminalRuns, 'control_operations' => $terminalOperations] as $table => $states) {
            if ($connection->table($table)->whereNotIn('state', $states)->exists()) {
                throw new BackupException($operation.'_state_not_quiescent');
            }
        }
    }

    private function assertCredentialsRelocated(Connection $connection, string $operation): void
    {
        $managed = $this->absolute($this->control->managedRoot, $operation);
        if ($this->absolute($this->control->keyRoot, $operation) !== $managed.'/credentials/deploy-keys') {
            return;
        }
        foreach ($connection->table('projects')->whereNotNull('deploy_key_reference')->pluck('deploy_key_reference') as $reference) {
            if (is_string($reference) && str_starts_with($reference, $managed.'/deploy-keys/')) {
                throw new BackupException($operation.'_credentials_relocation_required');
            }
        }
    }

    /** @return list<string> */
    private function migrations(Connection $connection): array
    {
        $names = $connection->table('migrations')->orderBy('migration')->pluck('migration')->all();
        foreach ($names as $name) {
            if (! is_string($name)) {
                throw new BackupException('restore_schema_mismatch');
            }
        }

        return $names;
    }

    /** @return list<array{id: string, version: int, check_hmac: string}> */
    private function keyProofs(Connection $connection, string $operation): array
    {
        $keys = [];
        foreach (['run_artifacts', 'run_events', 'check_results'] as $table) {
            $rows = $connection->table($table)->select('fingerprint_key_id', 'fingerprint_version')->whereNotNull('fingerprint_key_id')->distinct()->get();
            foreach ($rows as $row) {
                $id = $row->fingerprint_key_id;
                if (! is_string($id) || ! $this->keyring->has($id)) {
                    throw new BackupException($operation.'_redaction_key_missing');
                }
                if ($this->keyring->versionOf($id) !== $row->fingerprint_version) {
                    throw new BackupException($operation.'_redaction_key_mismatch');
                }
                $keys[$id] = ['id' => $id, 'version' => $this->keyring->versionOf($id),
                    'check_hmac' => hash_hmac('sha256', BackupManifest::KEY_CHECK, $this->keyring->keyOf($id))];
            }
        }
        ksort($keys);

        return array_values($keys);
    }

    private function readDatabase(string $path, bool $immutable): Connection
    {
        $uri = str_replace('%2F', '/', rawurlencode($path));
        if (preg_match('/\A[A-Za-z]:\//', $path) === 1) {
            $uri = '/'.str_replace('%3A', ':', $uri);
        }

        // Use the existing Laravel SQLite connector without live journal settings:
        // these connections are read-only and must not rewrite the inspected file.
        return $this->connections->make(['driver' => 'sqlite', 'database' => 'file:'.$uri.'?mode=ro'.($immutable ? '&immutable=1' : '')]);
    }

    /**
     * @param  list<array{path: string, sha256: string, size: int, mode: int}>  $files
     * @param  list<array{path: string, mode: int}>  $directories
     */
    private function inventory(string $path, string $relative, array &$files, array &$directories, string $operation, bool $validate = true): void
    {
        try {
            $this->redactor->assertValidInput($relative);
        } catch (Throwable) {
            throw new BackupException($operation.'_unsupported_entry');
        }
        $stat = $this->stat($path);
        if ($stat === false) {
            return;
        }
        $type = $stat['mode'] & 0170000;
        if (! in_array($type, [0040000, 0100000], true)) {
            throw new BackupException($operation.'_unsupported_entry');
        }
        if ($validate) {
            try {
                BackupManifest::assertRelativePath($relative, $type === 0040000);
            } catch (BackupException) {
                throw new BackupException($operation.'_unsupported_entry');
            }
        }
        if ($type === 0100000) {
            $files[] = $this->fileEntry($path, $relative, $operation);

            return;
        }
        $directories[] = ['path' => $relative, 'mode' => $stat['mode'] & 0777];
        $children = @scandir($path);
        if ($children === false) {
            throw new BackupException($operation.'_unreadable_entry');
        }
        foreach (array_diff($children, ['.', '..']) as $child) {
            $this->inventory($path.'/'.$child, ltrim($relative.'/'.$child, '/'), $files, $directories, $operation, $validate);
        }
    }

    /** @return array{path: string, sha256: string, size: int, mode: int} */
    private function fileEntry(string $path, string $relative, string $operation): array
    {
        $stat = $this->stat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            throw new BackupException($operation.'_unsupported_entry');
        }
        $hash = @hash_file('sha256', $path);
        if ($hash === false) {
            throw new BackupException($operation.'_unreadable_entry');
        }

        return ['path' => $relative, 'sha256' => $hash, 'size' => $stat['size'], 'mode' => $stat['mode'] & 0777];
    }

    /** @param array<string, string> $paths */
    private function sourcePath(string $relative, array $paths): string
    {
        foreach ($paths as $prefix => $path) {
            if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                return $path.substr($relative, strlen($prefix));
            }
        }
        throw new BackupException('backup_unsupported_entry');
    }

    private function restorePath(string $source, string $relative, string $target, BackupManifest $manifest): void
    {
        $directories = [];
        foreach ($manifest->directories as $directory) {
            if ($directory['path'] === $relative || str_starts_with($directory['path'], $relative.'/')) {
                $suffix = substr($directory['path'], strlen($relative));
                $this->makeDirectory($target.$suffix);
                $directories[] = ['path' => $suffix, 'mode' => $directory['mode']];
            }
        }
        foreach ($manifest->files as $file) {
            if ($file['path'] === $relative || str_starts_with($file['path'], $relative.'/')) {
                $this->copyFile($source.'/'.$file['path'], $target.substr($file['path'], strlen($relative)), $file);
            }
        }
        $this->directoryModes($target, $directories);
    }

    /** @param array{path: string, sha256: string, size: int, mode: int} $entry */
    private function copyFile(string $source, string $target, array $entry): void
    {
        $this->makeDirectory(dirname($target));
        if ($this->stat($target) !== false || ! $this->filesystem->copy($source, $target)) {
            throw new BackupException('restore_copy_failed');
        }
        $this->mode($target, $entry['mode']);
        if (hash_file('sha256', $target) !== $entry['sha256'] || filesize($target) !== $entry['size']) {
            throw new BackupException('restore_copy_failed');
        }
    }

    /** @param list<array{path: string, mode: int}> $directories */
    private function directoryModes(string $root, array $directories): void
    {
        usort($directories, static fn (array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));
        foreach ($directories as $directory) {
            $this->mode($root.'/'.ltrim($directory['path'], '/'), $directory['mode']);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! $this->filesystem->makeDirectory($path, 0700, true)) {
            throw new BackupException('backup_directory_failed');
        }
    }

    private function assertWritableParent(string $path): void
    {
        $parent = dirname($path);
        while ($this->stat($parent) === false) {
            $ancestor = dirname($parent);
            if ($ancestor === $parent) {
                throw new BackupException('restore_target_not_writable');
            }
            $parent = $ancestor;
        }
        // Rename needs write access to the parent, even when the child itself
        // belongs to the worker. Do not begin a known-impossible partial switch.
        if (! is_dir($parent) || ! $this->filesystem->isWritable($parent)) {
            throw new BackupException('restore_target_not_writable');
        }
    }

    private function mode(string $path, int $mode): void
    {
        if (! @chmod($path, $mode)) {
            throw new BackupException('backup_mode_failed');
        }
    }

    private function writeExclusive(string $path, string $bytes): void
    {
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            throw new BackupException('backup_write_failed');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
                throw new BackupException('backup_write_failed');
            }
        } finally {
            fclose($stream);
        }
        $this->mode($path, 0600);
    }

    private function removePrevious(string $path): void
    {
        $stat = $this->stat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & 0170000) === 0040000) {
            $children = @scandir($path);
            if ($children === false) {
                throw new BackupException('restore_cleanup_failed');
            }
            foreach (array_diff($children, ['.', '..']) as $child) {
                $this->removePrevious($path.'/'.$child);
            }
            if (! @rmdir($path)) {
                throw new BackupException('restore_cleanup_failed');
            }
        } elseif (($stat['mode'] & 0170000) !== 0100000 || ! @unlink($path)) {
            throw new BackupException('restore_cleanup_failed');
        }
    }

    /** @return array<string|int, int>|false */
    private function stat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    private function absolute(string $path, string $operation): string
    {
        $this->redactor->assertValidInput($path);
        $path = str_replace('\\', '/', $path);
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || str_contains($path, '://') || str_starts_with($path, '//')
            || preg_match('/\A(?:[A-Za-z]:)?\/\z/D', $path) === 1) {
            throw new BackupException($operation.'_path_invalid');
        }
        if (! str_starts_with($path, '/') && preg_match('/\A[A-Za-z]:\//', $path) !== 1) {
            $path = str_replace('\\', '/', (string) getcwd()).'/'.$path;
        }
        $path = rtrim($path, '/');
        foreach (explode('/', $path) as $index => $segment) {
            if (($segment === '' && $index !== 0) || $segment === '.' || $segment === '..'
                || str_ends_with($segment, '.') || str_ends_with($segment, ' ')
                || (str_contains($segment, ':') && ! ($index === 0 && preg_match('/\A[A-Za-z]:\z/D', $segment) === 1))) {
                throw new BackupException($operation.'_path_invalid');
            }
        }
        $cursor = $path;
        while ($cursor !== '') {
            $stat = $this->stat($cursor);
            if ($stat !== false && ! in_array($stat['mode'] & 0170000, [0040000, 0100000], true)) {
                throw new BackupException($operation.'_unsupported_entry');
            }
            $parent = str_replace('\\', '/', dirname($cursor));
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }

        $existing = $path;
        while ($this->stat($existing) === false) {
            $parent = str_replace('\\', '/', dirname($existing));
            if ($parent === $existing) {
                throw new BackupException($operation.'_path_invalid');
            }
            $existing = $parent;
        }
        if ($existing !== $path && ! is_dir($existing)) {
            throw new BackupException($operation.'_path_invalid');
        }
        $resolved = realpath($existing);
        if ($resolved === false) {
            throw new BackupException($operation.'_path_invalid');
        }

        return rtrim(str_replace('\\', '/', $resolved), '/').substr($path, strlen($existing));
    }

    /** @param list<string> $paths */
    private function assertDisjoint(string $path, array $paths, string $operation): void
    {
        $path = strtolower($path);
        foreach ($paths as $other) {
            $other = strtolower($other);
            if ($path === $other || str_starts_with($path, $other.'/') || str_starts_with($other, $path.'/')) {
                throw new BackupException($operation.'_path_overlap');
            }
        }
    }

    /** @param array<string, string> $paths */
    private function assertBackupLocation(string $path, array $paths, string $operation): void
    {
        $this->assertDisjoint($path, array_values($paths), $operation);
        $managed = $this->absolute($this->control->managedRoot, $operation);
        // Only this worker-private subtree may contain backups in the managed
        // volume. Staging, clones and immutable effect locks remain excluded.
        if (! str_starts_with(strtolower($path), strtolower($managed.'/backups/'))) {
            $this->assertDisjoint($path, [$managed], $operation);
        }
    }

    private function timestamp(): string
    {
        return Date::now()->utc()->format('Ymd\THis.u\Z');
    }
}
