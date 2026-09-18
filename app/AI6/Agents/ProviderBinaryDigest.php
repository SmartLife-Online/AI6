<?php

namespace App\AI6\Agents;

use Closure;

/** Process-local digest cache; metadata is refreshed before every lookup. */
final class ProviderBinaryDigest
{
    /** @var array<string, array{identity: array<int, int>, digest: string}> */
    private array $cache = [];

    /** @param null|Closure(string): (string|false) $read */
    public function __construct(private readonly ?Closure $read = null) {}

    public function sha256(string $path): string|false
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || ! is_file($path) || is_link($path)) {
            unset($this->cache[$path]);

            return false;
        }
        $identity = [$stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']];
        if (($this->cache[$path]['identity'] ?? null) === $identity) {
            return $this->cache[$path]['digest'];
        }
        $digest = $this->read === null ? hash_file('sha256', $path) : ($this->read)($path);
        clearstatcache(true, $path);
        $after = @stat($path);
        if ($digest === false || $after === false || [$after['dev'], $after['ino'], $after['size'], $after['mtime'], $after['ctime']] !== $identity) {
            unset($this->cache[$path]);

            return false;
        }
        $this->cache[$path] = ['identity' => $identity, 'digest' => $digest];

        return $digest;
    }
}
