<?php

namespace App\AI6\Shared\Process;

use App\AI6\Shared\Config\ConfigurationException;
use Closure;

final readonly class ProcessPolicyRegistry
{
    /** @param array<string, ProcessPolicy> $policies */
    public function __construct(
        private array $policies,
        public ProcessLimits $serverLimits,
        /** @var null|Closure(): string */
        private ?Closure $controlWorkingRoot = null,
    ) {}

    /** @param null|Closure(): string $controlWorkingRoot */
    public static function fromConfiguredValues(?Closure $controlWorkingRoot = null): self
    {
        $configured = config('ai6.process.policies');
        if (! is_array($configured)) {
            throw new ConfigurationException('Configuration key ai6.process.policies must be an object.');
        }

        $policies = [];
        foreach (ProcessPolicyName::cases() as $name) {
            $value = $configured[$name->value] ?? null;
            if (! is_array($value)) {
                throw new ConfigurationException("Process policy {$name->value} is missing.");
            }

            $executables = self::stringList($value['allowed_executables'] ?? null, 'allowed_executables');
            $environment = self::stringList($value['environment_allowlist'] ?? null, 'environment_allowlist');
            $workingRoots = self::stringList($value['working_roots'] ?? null, 'working_roots');
            if ($name === ProcessPolicyName::CONTROL) {
                $managedRoot = config('ai6.control_operations.managed_root');
                if (is_string($managedRoot) && $managedRoot !== '') {
                    $workingRoots[] = $managedRoot;
                    $workingRoots = array_values(array_unique($workingRoots));
                }
            }

            $policies[$name->value] = new ProcessPolicy(
                $name,
                self::positive($value['timeout_seconds'] ?? null, $name, 'timeout_seconds'),
                self::positive($value['output_limit_bytes'] ?? null, $name, 'output_limit_bytes'),
                $executables,
                $environment,
                $workingRoots,
                filter_var($value['requires_process_group'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                    ?? throw new ConfigurationException("Process policy {$name->value} has an invalid process-group setting."),
                self::positive($value['cancel_grace_milliseconds'] ?? null, $name, 'cancel_grace_milliseconds'),
            );
        }

        $limits = config('ai6.process.server_limits');
        if (! is_array($limits)) {
            throw new ConfigurationException('Configuration key ai6.process.server_limits must be an object.');
        }

        return new self($policies, new ProcessLimits(
            self::positive($limits['runtime_seconds'] ?? null, ProcessPolicyName::CONTROL, 'runtime_seconds'),
            self::positive($limits['output_bytes'] ?? null, ProcessPolicyName::CONTROL, 'output_bytes'),
            self::positive($limits['process_count'] ?? null, ProcessPolicyName::CONTROL, 'process_count'),
            self::positive($limits['file_count'] ?? null, ProcessPolicyName::CONTROL, 'file_count'),
            self::positive($limits['total_bytes'] ?? null, ProcessPolicyName::CONTROL, 'total_bytes'),
            self::positive($limits['artifact_count'] ?? null, ProcessPolicyName::CONTROL, 'artifact_count'),
        ), $controlWorkingRoot);
    }

    public function get(ProcessPolicyName $name): ProcessPolicy
    {
        $policy = $this->policies[$name->value];
        if ($name !== ProcessPolicyName::CONTROL) {
            return $policy;
        }

        $managedRoot = config('ai6.control_operations.managed_root');
        $roots = $policy->workingRoots;
        if (is_string($managedRoot) && $managedRoot !== '') {
            $roots[] = $managedRoot;
        }
        if ($this->controlWorkingRoot !== null) {
            $roots[] = ($this->controlWorkingRoot)();
        }

        return new ProcessPolicy(
            $policy->name,
            $policy->timeoutSeconds,
            $policy->outputLimitBytes,
            $policy->allowedExecutables,
            $policy->environmentAllowlist,
            array_values(array_unique($roots)),
            $policy->requiresProcessGroup,
            $policy->cancelGraceMilliseconds,
        );
    }

    private static function positive(mixed $value, ProcessPolicyName $name, string $field): int
    {
        if (! is_int($value) && (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)) {
            throw new ConfigurationException("Process policy {$name->value} has an invalid {$field}.");
        }

        $parsed = (int) $value;
        if ($parsed < 1) {
            throw new ConfigurationException("Process policy {$name->value} has an invalid {$field}.");
        }

        return $parsed;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ConfigurationException("A process policy has an invalid {$field}.");
        }

        foreach ($value as $entry) {
            if (! is_string($entry) || $entry === '' || str_contains($entry, "\0")) {
                throw new ConfigurationException("A process policy has an invalid {$field} entry.");
            }
        }

        if (count(array_unique($value)) !== count($value)) {
            throw new ConfigurationException("A process policy has duplicate {$field} entries.");
        }

        return $value;
    }
}
