<?php

namespace App\AI6\Shared\Operations;

use App\AI6\Shared\Redaction\Redactor;
use Throwable;

/** A closed inventory of a quiescent backup, never an authenticity claim. */
final readonly class BackupManifest
{
    public const SCHEMA = 'ai6.backup.v1';

    public const KEY_CHECK = 'ai6.backup.v1:redaction-key-check';

    /**
     * @param  list<string>  $migrations
     * @param  list<array{path: string, sha256: string, size: int, mode: int}>  $files
     * @param  list<array{path: string, mode: int}>  $directories
     * @param  list<array{id: string, version: int, check_hmac: string}>  $redactionKeys
     */
    public function __construct(
        public string $createdAt,
        public string $environment,
        public array $migrations,
        public array $files,
        public array $directories,
        public array $redactionKeys,
    ) {}

    public function bytes(): string
    {
        return json_encode([
            'schema' => self::SCHEMA,
            'created_at' => $this->createdAt,
            'environment' => $this->environment,
            'migrations' => $this->migrations,
            'files' => $this->files,
            'directories' => $this->directories,
            'redaction_keys' => $this->redactionKeys,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    public static function read(string $bytes, Redactor $redactor): self
    {
        try {
            // Paths and digests must remain exact. Inventory values are never rendered.
            $redactor->assertValidInput($bytes);
            $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new BackupException('restore_manifest_invalid');
        }
        if (! is_array($data) || ! self::hasKeys($data, ['schema', 'created_at', 'environment', 'migrations', 'files', 'directories', 'redaction_keys'])
            || $data['schema'] !== self::SCHEMA
            || ! is_string($data['created_at']) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $data['created_at']) !== 1
            || ! is_string($data['environment']) || $data['environment'] === '') {
            throw new BackupException('restore_manifest_invalid');
        }
        foreach (['files', 'directories', 'migrations', 'redaction_keys'] as $field) {
            if (! is_array($data[$field]) || ! array_is_list($data[$field])) {
                throw new BackupException('restore_manifest_invalid');
            }
        }
        $seen = [];
        foreach ($data['migrations'] as $name) {
            if (! is_string($name) || preg_match('/\A[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\z/D', $name) !== 1 || isset($seen[$name])) {
                throw new BackupException('restore_manifest_invalid');
            }
            $seen[$name] = true;
        }
        $seen = [];
        foreach (['files', 'directories'] as $type) {
            foreach ($data[$type] as $entry) {
                $keys = $type === 'files' ? ['path', 'sha256', 'size', 'mode'] : ['path', 'mode'];
                if (! is_array($entry) || ! self::hasKeys($entry, $keys) || ! is_string($entry['path'])) {
                    throw new BackupException('restore_manifest_invalid');
                }
                self::assertRelativePath($entry['path'], $type === 'directories');
                $identity = strtolower($entry['path']);
                if (isset($seen[$identity])) {
                    throw new BackupException('restore_duplicate_path');
                }
                $seen[$identity] = true;
                if (! is_int($entry['mode']) || $entry['mode'] < 0 || $entry['mode'] > 0777
                    || ($type === 'files' && (! is_int($entry['size']) || $entry['size'] < 0
                        || ! is_string($entry['sha256']) || preg_match('/\A[0-9a-f]{64}\z/D', $entry['sha256']) !== 1))) {
                    throw new BackupException('restore_manifest_invalid');
                }
            }
        }
        if (! in_array('database.sqlite', array_column($data['files'], 'path'), true)) {
            throw new BackupException('restore_manifest_invalid');
        }
        $seen = [];
        foreach ($data['redaction_keys'] as $key) {
            if (! is_array($key) || ! self::hasKeys($key, ['id', 'version', 'check_hmac'])
                || ! is_string($key['id']) || $key['id'] === '' || isset($seen[$key['id']])
                || ! is_int($key['version']) || $key['version'] < 1
                || ! is_string($key['check_hmac']) || preg_match('/\A[0-9a-f]{64}\z/D', $key['check_hmac']) !== 1) {
                throw new BackupException('restore_manifest_invalid');
            }
            $seen[$key['id']] = true;
        }

        return new self($data['created_at'], $data['environment'], $data['migrations'], $data['files'], $data['directories'], $data['redaction_keys']);
    }

    public static function assertRelativePath(string $path, bool $directory = false): void
    {
        if ($path === '' || preg_match('/[\x00-\x20\x7f\\\\:<>"|?*]/', $path) === 1) {
            throw new BackupException('restore_path_invalid');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_ends_with($segment, '.')
                || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|\z)/i', $segment) === 1) {
                throw new BackupException('restore_path_invalid');
            }
        }
        $allowed = str_starts_with($path, 'run-artifacts/') || str_starts_with($path, 'managed/deploy-keys/');
        $allowed = $allowed || ($directory
            ? in_array($path, ['run-artifacts', 'managed', 'managed/deploy-keys'], true)
            : in_array($path, ['database.sqlite', 'managed/known_hosts'], true));
        if (! $allowed) {
            throw new BackupException('restore_path_invalid');
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $keys
     */
    private static function hasKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
