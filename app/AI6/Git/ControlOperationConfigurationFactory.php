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
        foreach (['managed_root', 'key_root', 'ssh_keygen_binary', 'ssh_keygen_wrapper', 'known_hosts_file'] as $field) {
            $value = $configuration[$field] ?? null;
            if (! is_string($value) || trim($value) === '' || str_contains($value, "\0")) {
                return new ConfigurationViolation(sprintf('Configuration key ai6.control_operations.%s must be a non-empty path.', $field));
            }
            if ($field === 'known_hosts_file' && ! $this->canonicalAbsolutePath($value)) {
                return new ConfigurationViolation('Configuration key AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE must be a canonical file below the managed root.');
            }

            $paths[$field] = rtrim($value, '/\\');
        }

        $managedRoot = str_replace('\\', '/', $paths['managed_root']);
        $keyRoot = str_replace('\\', '/', $paths['key_root']);
        if ($keyRoot === $managedRoot || ! str_starts_with($keyRoot.'/', $managedRoot.'/')) {
            return new ConfigurationViolation('The deploy-key root must be contained below the managed root.');
        }
        $knownHostsFile = str_replace('\\', '/', $paths['known_hosts_file']);
        if ($knownHostsFile === $managedRoot
            || ! str_starts_with($knownHostsFile.'/', $managedRoot.'/')) {
            return new ConfigurationViolation('Configuration key AI6_CONTROL_OPERATION_KNOWN_HOSTS_FILE must be a canonical file below the managed root.');
        }

        $managedRefs = $this->managedRefs($configuration['managed_ref_allowlist'] ?? null);
        if ($managedRefs instanceof ConfigurationViolation) {
            return $managedRefs;
        }

        $refreshBasePath = RefreshPathPolicy::canonicalBasePath($configuration['refresh_base_path'] ?? null);
        if ($refreshBasePath === null) {
            return new ConfigurationViolation('Configuration key AI6_CONTROL_OPERATION_REFRESH_BASE_PATH must be a canonical repository-relative directory.');
        }

        $parser = new StrictPositiveIntegerParser;
        $integers = [];
        foreach (['lease_seconds', 'heartbeat_seconds', 'reconciler_seconds', 'max_attempts', 'stale_seconds', 'reconciliation_budget'] as $field) {
            $maximum = in_array($field, ['max_attempts', 'reconciliation_budget'], true) ? 100 : 31536000;
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
            $paths['known_hosts_file'],
            $managedRefs,
            $integers['stale_seconds'],
            $integers['reconciliation_budget'],
            $refreshBasePath,
        );
    }

    /** @return non-empty-list<string>|ConfigurationViolation */
    private function managedRefs(mixed $value): array|ConfigurationViolation
    {
        if (! is_string($value) || trim($value) === '') {
            return new ConfigurationViolation('Configuration key AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST must contain exact refs.');
        }

        $refs = array_map('trim', explode(',', $value));
        if (in_array('', $refs, true) || count($refs) !== count(array_unique($refs))) {
            return new ConfigurationViolation('Configuration key AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST contains an empty or duplicate ref.');
        }

        foreach ($refs as $ref) {
            if (! $this->validManagedRef($ref)) {
                return new ConfigurationViolation('Configuration key AI6_CONTROL_OPERATION_MANAGED_REF_ALLOWLIST contains a non-canonical or wildcard ref.');
            }
        }

        return $refs;
    }

    private function validManagedRef(string $ref): bool
    {
        if (preg_match('/\Arefs\/heads\/[A-Za-z0-9._\/-]+\z/D', $ref) !== 1
            || str_contains($ref, '..')
            || str_contains($ref, '//')
            || str_ends_with($ref, '/')) {
            return false;
        }

        foreach (explode('/', substr($ref, strlen('refs/heads/'))) as $component) {
            if ($component === ''
                || str_starts_with($component, '.')
                || str_ends_with($component, '.')
                || str_ends_with($component, '.lock')) {
                return false;
            }
        }

        return true;
    }

    private function canonicalAbsolutePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        if ((! str_starts_with($normalized, '/') && preg_match('/\A[A-Za-z]:\//D', $normalized) !== 1)
            || str_contains($normalized, '//')
            || str_ends_with($normalized, '/')) {
            return false;
        }

        foreach (explode('/', $normalized) as $component) {
            if ($component === '.' || $component === '..') {
                return false;
            }
        }

        return true;
    }
}
