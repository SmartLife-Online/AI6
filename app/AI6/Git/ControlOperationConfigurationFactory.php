<?php

namespace App\AI6\Git;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use App\AI6\Shared\Process\ProcessConfiguration;

final class ControlOperationConfigurationFactory
{
    public function __construct(private readonly ?ProcessConfiguration $processConfiguration = null) {}

    public function fromConfiguredValues(): ControlOperationConfiguration
    {
        $result = $this->inspect(config('ai6.control_operations'));

        if ($result instanceof ConfigurationViolation) {
            throw new ConfigurationException($result->message);
        }

        return $result;
    }

    public function inspect(mixed $configuration): ControlOperationConfiguration|ConfigurationViolation
    {
        if (! is_array($configuration)) {
            return new ConfigurationViolation('Configuration key ai6.control_operations must be an array.');
        }

        $paths = [];
        foreach (['managed_root', 'key_root', 'ssh_keygen_binary', 'ssh_keygen_wrapper'] as $field) {
            $value = $configuration[$field] ?? null;
            if (! is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
                return new ConfigurationViolation(sprintf('Configuration key ai6.control_operations.%s must be a non-empty path.', $field));
            }

            $paths[$field] = rtrim($value, '/\\');
        }

        $managedRoot = str_replace('\\', '/', $paths['managed_root']);
        $keyRoot = str_replace('\\', '/', $paths['key_root']);
        if ($keyRoot === $managedRoot || ! str_starts_with($keyRoot.'/', $managedRoot.'/')) {
            return new ConfigurationViolation('The deploy-key root must be contained below the managed root.');
        }

        $parser = new StrictPositiveIntegerParser;
        $integers = [];
        foreach (['lease_seconds', 'heartbeat_seconds', 'reconciler_seconds', 'max_attempts'] as $field) {
            $maximum = $field === 'max_attempts' ? 100 : 86400;
            $parsed = $parser->parse(
                'AI6_CONTROL_OPERATION_'.strtoupper($field),
                $configuration[$field] ?? null,
                $maximum,
            );
            if ($parsed instanceof ConfigurationViolation) {
                return $parsed;
            }

            $integers[$field] = $parsed;
        }

        if ($integers['heartbeat_seconds'] >= $integers['lease_seconds']) {
            return new ConfigurationViolation('The control-operation heartbeat interval must be shorter than the lease.');
        }

        if ($this->processConfiguration !== null
            && $this->processConfiguration->lockWaitMilliseconds >= $this->processConfiguration->wrapperReadyTimeoutSeconds * 1000) {
            return new ConfigurationViolation('The effect-lock wait must be shorter than the blocked-process readiness timeout.');
        }

        return new ControlOperationConfiguration(
            $paths['managed_root'],
            $paths['key_root'],
            $paths['ssh_keygen_binary'],
            $paths['ssh_keygen_wrapper'],
            $integers['lease_seconds'],
            $integers['heartbeat_seconds'],
            $integers['reconciler_seconds'],
            $integers['max_attempts'],
        );
    }
}
