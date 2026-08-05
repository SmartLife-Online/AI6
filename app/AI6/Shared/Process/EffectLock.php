<?php

namespace App\AI6\Shared\Process;

final class EffectLock
{
    public function __construct(private readonly ProcessConfiguration $configuration) {}

    public function validate(string $name): ValidatedLockObject
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return $this->invalid(EffectLockOutcome::CONFIGURATION_ERROR, 'Effect locks require a POSIX filesystem.');
        }

        if (preg_match('/\Alock-([0-9]{4})\z/D', $name, $matches) !== 1) {
            return $this->invalid(EffectLockOutcome::CONFLICT, 'The effect lock name is invalid.');
        }

        $index = (int) $matches[1];
        if ($index < 1 || $index > $this->configuration->lockObjectCount) {
            return $this->invalid(EffectLockOutcome::CONFIGURATION_ERROR, 'The effect lock name is not provisioned.');
        }

        $runtimeStat = @stat('/proc/self');
        if (! is_array($runtimeStat)) {
            return $this->invalid(EffectLockOutcome::CONFIGURATION_ERROR, 'The runtime identity could not be established.');
        }

        if ((int) $runtimeStat['uid'] === $this->configuration->lockOwnerUid) {
            return $this->invalid(EffectLockOutcome::CONFIGURATION_ERROR, 'The effect lock owner must differ from the runtime identity.');
        }

        $directory = rtrim($this->configuration->lockDirectory, '/');
        clearstatcache(true, $directory);
        $directoryStat = @lstat($directory);
        if (! is_array($directoryStat) || is_link($directory) || ! is_dir($directory)) {
            return $this->invalid(EffectLockOutcome::CONFIGURATION_ERROR, 'The effect lock directory is unavailable.');
        }

        if (($directoryStat['mode'] & 0777) !== 0555 || (int) $directoryStat['uid'] !== $this->configuration->lockOwnerUid) {
            return $this->invalid(EffectLockOutcome::CONFLICT, 'The effect lock directory ownership or mode is invalid.');
        }

        $path = $directory.'/'.$name;
        clearstatcache(true, $path);
        $objectStat = @lstat($path);
        if (! is_array($objectStat)) {
            return $this->invalid(EffectLockOutcome::CONFIGURATION_ERROR, 'The effect lock object is not provisioned.');
        }

        if (is_link($path) || ! is_file($path)) {
            return $this->invalid(EffectLockOutcome::CONFLICT, 'The effect lock object type is invalid.');
        }

        if (($objectStat['mode'] & 0777) !== 0444
            || (int) $objectStat['uid'] !== $this->configuration->lockOwnerUid
            || (int) $objectStat['uid'] !== (int) $directoryStat['uid']) {
            return $this->invalid(EffectLockOutcome::CONFLICT, 'The effect lock object ownership or mode is invalid.');
        }

        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        if ($realDirectory === false || $realPath === false || dirname($realPath) !== $realDirectory || $realPath !== $path) {
            return $this->invalid(EffectLockOutcome::CONFLICT, 'The effect lock object does not resolve safely.');
        }

        return new ValidatedLockObject(
            EffectLockOutcome::ACQUIRED,
            $path,
            $this->identity($objectStat),
            'The effect lock object is valid.',
        );
    }

    public function acquire(string $name, ?int $waitMilliseconds = null): EffectLockAcquisition
    {
        $validated = $this->validate($name);
        if (! $validated->valid() || $validated->path === null || $validated->identity === null) {
            return new EffectLockAcquisition($validated->outcome, null, $validated->message);
        }

        $stream = @fopen($validated->path, 'rb');
        if (! is_resource($stream)) {
            return new EffectLockAcquisition(EffectLockOutcome::CONFLICT, null, 'The effect lock object could not be opened read-only.');
        }

        $wait = min($waitMilliseconds ?? $this->configuration->lockWaitMilliseconds, $this->configuration->lockWaitMilliseconds);
        $deadline = microtime(true) + ($wait / 1000);
        do {
            if (@flock($stream, LOCK_EX | LOCK_NB)) {
                clearstatcache(true, $validated->path);
                $heldStat = fstat($stream);
                $currentStat = @lstat($validated->path);
                if (! is_array($heldStat)
                    || ! is_array($currentStat)
                    || $this->identity($heldStat) !== $validated->identity
                    || $this->identity($currentStat) !== $validated->identity) {
                    fclose($stream);

                    return new EffectLockAcquisition(EffectLockOutcome::CONFLICT, null, 'The effect lock object identity changed.');
                }

                return new EffectLockAcquisition(
                    EffectLockOutcome::ACQUIRED,
                    new EffectLockHandle($stream, $name, $validated->identity),
                    'The effect lock was acquired.',
                );
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        fclose($stream);

        return new EffectLockAcquisition(EffectLockOutcome::UNAVAILABLE, null, 'The effect lock is currently unavailable.');
    }

    /** @param array<mixed> $stat */
    private function identity(array $stat): string
    {
        return sprintf('%s:%s', (string) $stat['dev'], (string) $stat['ino']);
    }

    private function invalid(EffectLockOutcome $outcome, string $message): ValidatedLockObject
    {
        return new ValidatedLockObject($outcome, null, null, $message);
    }
}
