<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Git\ControlOperationConfigurationFactory;
use App\AI6\Git\GitConfigurationFactory;
use App\AI6\Shared\Config\ConfigurationException;

final readonly class GitDoctorCheck implements DoctorCheck
{
    public function __construct(
        private GitConfigurationFactory $gitFactory,
        private ControlOperationConfigurationFactory $controlFactory,
    ) {}

    public function label(): string
    {
        return 'Git';
    }

    public function run(): DoctorCheckResult
    {
        if (config('ai6.runtime_role') !== 'worker') {
            return new DoctorCheckResult(true, ['Zuständigkeit' => 'nicht zuständig']);
        }

        try {
            $git = $this->gitFactory->fromConfiguredValues();
            $control = $this->controlFactory->fromConfiguredValues();
        } catch (ConfigurationException) {
            return new DoctorCheckResult(false, ['Rolle' => 'worker', 'Grund' => 'git_configuration_invalid']);
        }

        foreach ([
            [$git->gitBinary, 'git_binary_unavailable'],
            [$git->sshBinary, 'ssh_binary_unavailable'],
            [$git->globalConfig, 'git_global_config_unavailable'],
            [$git->hooksPath, 'git_hooks_path_unavailable'],
            [$control->knownHostsFile, 'known_hosts_missing'],
        ] as [$path, $reason]) {
            $valid = match ($reason) {
                'git_binary_unavailable', 'ssh_binary_unavailable' => $this->regularExecutable($path),
                'git_hooks_path_unavailable' => is_dir($path) && ! is_link($path),
                default => is_file($path) && ! is_link($path),
            };
            if (! $valid) {
                return new DoctorCheckResult(false, ['Rolle' => 'worker', 'Grund' => $reason]);
            }
        }

        if (in_array((string) config('app.env'), ['local', 'testing'], true)) {
            return new DoctorCheckResult(true, ['Rolle' => 'worker', 'Status' => 'Git- und SSH-Grundlagen geprüft']);
        }

        if ($git->allowedHosts === [] || $git->allowedRemotePaths === [] || $git->pinnedHostKeyFingerprints === []) {
            return new DoctorCheckResult(false, ['Rolle' => 'worker', 'Grund' => 'git_allowlist_empty']);
        }

        return new DoctorCheckResult(true, ['Rolle' => 'worker', 'Status' => 'Git- und SSH-Grundlagen geprüft']);
    }

    private function regularExecutable(string $path): bool
    {
        return is_file($path) && ! is_link($path) && is_executable($path);
    }
}
