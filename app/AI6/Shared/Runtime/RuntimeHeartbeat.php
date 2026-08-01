<?php

namespace App\AI6\Shared\Runtime;

use JsonException;
use RuntimeException;

final class RuntimeHeartbeat
{
    public const WORKER_DIRECTORY = '/run/ai6/heartbeat/worker';

    private const BOOT_ID_FILE = 'boot-id';

    private const HEARTBEAT_FILE = 'heartbeat.json';

    private const ROLES = ['worker', 'scheduler', 'agent', 'checker'];

    public function __construct(private readonly string $directory)
    {
        if ($directory === '' || ! is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('The heartbeat directory must be an existing regular directory.');
        }
    }

    public function write(string $role, ?int $recordedAt = null): void
    {
        $this->assertRole($role);
        $bootId = $this->readBootId();
        $payload = json_encode([
            'role' => $role,
            'boot_id' => $bootId,
            'recorded_at' => $recordedAt ?? time(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $temporaryPath = $this->path(self::HEARTBEAT_FILE.'.'.bin2hex(random_bytes(8)).'.tmp');

        try {
            if (file_put_contents($temporaryPath, $payload."\n", LOCK_EX) === false) {
                throw new RuntimeException('The heartbeat could not be written.');
            }

            if (! rename($temporaryPath, $this->path(self::HEARTBEAT_FILE))) {
                throw new RuntimeException('The heartbeat could not be published.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function bootId(): string
    {
        return $this->readBootId();
    }

    /** @return array{healthy: bool, age: int|null} */
    public function status(string $role, int $maxAge, ?int $now = null): array
    {
        $this->assertRole($role);

        if ($maxAge < 1) {
            return ['healthy' => false, 'age' => null];
        }

        try {
            $heartbeat = $this->readHeartbeat();
            $age = ($now ?? time()) - $heartbeat['recorded_at'];
            $healthy = $heartbeat['role'] === $role
                && hash_equals($this->readBootId(), $heartbeat['boot_id'])
                && $age >= 0
                && $age <= $maxAge;

            return ['healthy' => $healthy, 'age' => $age];
        } catch (RuntimeException|JsonException) {
            return ['healthy' => false, 'age' => null];
        }
    }

    /** @return array{role: string, boot_id: string, recorded_at: int} */
    private function readHeartbeat(): array
    {
        $contents = @file_get_contents($this->path(self::HEARTBEAT_FILE));

        if ($contents === false) {
            throw new RuntimeException('The heartbeat is missing.');
        }

        $payload = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if (! is_array($payload)
            || ! is_string($payload['role'] ?? null)
            || ! is_string($payload['boot_id'] ?? null)
            || ! is_int($payload['recorded_at'] ?? null)
            || preg_match('/\A[0-9a-f]{32}\z/D', $payload['boot_id']) !== 1
        ) {
            throw new RuntimeException('The heartbeat payload is invalid.');
        }

        return [
            'role' => $payload['role'],
            'boot_id' => $payload['boot_id'],
            'recorded_at' => $payload['recorded_at'],
        ];
    }

    private function readBootId(): string
    {
        $bootId = @file_get_contents($this->path(self::BOOT_ID_FILE));

        if ($bootId === false) {
            throw new RuntimeException('The container boot ID is missing.');
        }

        $bootId = trim($bootId);

        if (preg_match('/\A[0-9a-f]{32}\z/D', $bootId) !== 1) {
            throw new RuntimeException('The container boot ID is invalid.');
        }

        return $bootId;
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new RuntimeException('The runtime role is invalid.');
        }
    }

    private function path(string $file): string
    {
        return rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.$file;
    }
}
