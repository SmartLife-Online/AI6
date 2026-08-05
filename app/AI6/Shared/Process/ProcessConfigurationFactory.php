<?php

namespace App\AI6\Shared\Process;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final class ProcessConfigurationFactory
{
    public function __construct(
        private readonly StrictPositiveIntegerParser $integerParser = new StrictPositiveIntegerParser,
    ) {}

    public function fromConfiguredValues(): ProcessConfiguration
    {
        $result = $this->inspectRuntime(config('ai6.process'));

        if ($result instanceof ConfigurationViolation) {
            throw new ConfigurationException($result->message);
        }

        return $result;
    }

    public function inspectRuntime(mixed $configuration): ProcessConfiguration|ConfigurationViolation
    {
        $result = $this->inspect($configuration);
        if ($result instanceof ConfigurationViolation || DIRECTORY_SEPARATOR !== '/') {
            return $result;
        }

        $wrapperViolation = ImmutableRuntimeFile::violation($result->wrapperScript, false);
        if ($wrapperViolation !== null) {
            return new ConfigurationViolation(sprintf('Configuration key ai6.process.wrapper_script %s.', $wrapperViolation));
        }

        foreach ([
            [$result->shellBinary, 'AI6_PROCESS_SHELL_BINARY'],
            [$result->setsidBinary, 'AI6_PROCESS_SETSID_BINARY'],
            [$result->processGroupKillBinary, 'AI6_PROCESS_GROUP_KILL_BINARY'],
        ] as [$path, $key]) {
            if (! is_string($path)) {
                return new ConfigurationViolation(sprintf('Configuration key %s must name an immutable executable.', $key));
            }

            $violation = ImmutableRuntimeFile::violation($path, true);
            if ($violation !== null) {
                return new ConfigurationViolation(sprintf('Configuration key %s %s.', $key, $violation));
            }
        }

        return $result;
    }

    public function inspect(mixed $configuration): ProcessConfiguration|ConfigurationViolation
    {
        if (! is_array($configuration)) {
            return new ConfigurationViolation('Configuration key ai6.process must be an array.');
        }

        $integers = [
            'timeout_seconds' => ['AI6_PROCESS_TIMEOUT_SECONDS', 86400],
            'output_limit_bytes' => ['AI6_PROCESS_OUTPUT_LIMIT_BYTES', 1073741824],
            'cancel_grace_milliseconds' => ['AI6_PROCESS_CANCEL_GRACE_MILLISECONDS', 60000],
            'wrapper_ready_timeout_seconds' => ['AI6_PROCESS_WRAPPER_READY_TIMEOUT_SECONDS', 300],
            'lock_object_count' => ['AI6_EFFECT_LOCK_OBJECT_COUNT', 10000],
            'lock_wait_milliseconds' => ['AI6_EFFECT_LOCK_WAIT_MILLISECONDS', 3600000],
        ];
        $parsed = [];

        foreach ($integers as $field => [$key, $maximum]) {
            $value = $this->integerParser->parse($key, $configuration[$field] ?? null, $maximum);

            if ($value instanceof ConfigurationViolation) {
                return $value;
            }

            $parsed[$field] = $value;
        }

        $ownerUid = $configuration['lock_owner_uid'] ?? null;
        if (! is_int($ownerUid) && (! is_string($ownerUid) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $ownerUid) !== 1)) {
            return new ConfigurationViolation('Configuration key AI6_EFFECT_LOCK_OWNER_UID must be a non-negative integer.');
        }
        $ownerUid = (int) $ownerUid;

        $paths = [];
        foreach (['wrapper_script', 'shell_binary', 'lock_directory'] as $field) {
            $value = $configuration[$field] ?? null;
            if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
                return new ConfigurationViolation(sprintf('Configuration key ai6.process.%s must be a non-empty path.', $field));
            }
            $paths[$field] = $value;
        }

        $setsid = $configuration['setsid_binary'] ?? null;
        if ($setsid !== null && (! is_string($setsid) || $setsid === '' || str_contains($setsid, "\0"))) {
            return new ConfigurationViolation('Configuration key ai6.process.setsid_binary must be null or a non-empty path.');
        }

        $processGroupKill = $configuration['process_group_kill_binary'] ?? null;
        if ($processGroupKill !== null && (! is_string($processGroupKill) || $processGroupKill === '' || str_contains($processGroupKill, "\0"))) {
            return new ConfigurationViolation('Configuration key ai6.process.process_group_kill_binary must be null or a non-empty path.');
        }

        if (DIRECTORY_SEPARATOR === '/' && ($setsid === null || $processGroupKill === null)) {
            return new ConfigurationViolation('POSIX control processes require setsid and process-group kill binaries.');
        }

        return new ProcessConfiguration(
            $parsed['timeout_seconds'],
            $parsed['output_limit_bytes'],
            $parsed['cancel_grace_milliseconds'],
            $parsed['wrapper_ready_timeout_seconds'],
            $paths['wrapper_script'],
            $paths['shell_binary'],
            $setsid,
            $processGroupKill,
            $paths['lock_directory'],
            $parsed['lock_object_count'],
            $parsed['lock_wait_milliseconds'],
            $ownerUid,
        );
    }
}
