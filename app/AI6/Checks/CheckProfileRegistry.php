<?php

namespace App\AI6\Checks;

use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;

/**
 * The one place that knows which check profiles exist and what they run.
 *
 * Both the project validation (CheckProfileAllowlist) and the execution
 * (CheckRunner) resolve their names here, so a project can never be validated
 * against one list and executed against another.
 *
 * A profile is a program plus an argument list. A shell string, a shell
 * interpreter, inline code and a program outside the checker process policy are
 * named configuration errors, not something that gets executed.
 */
final readonly class CheckProfileRegistry
{
    /**
     * Program basenames that would turn an argument list back into a shell
     * string. Rejected regardless of the executables a policy allows.
     *
     * @var list<string>
     */
    private const SHELL_INTERPRETERS = [
        'sh', 'bash', 'dash', 'zsh', 'ksh', 'csh', 'tcsh', 'fish', 'ash', 'busybox',
        'cmd', 'cmd.exe', 'powershell', 'powershell.exe', 'pwsh', 'pwsh.exe',
    ];

    /**
     * Flags that hand a program inline code instead of a file. The php, node,
     * perl and python spellings all live here.
     *
     * @var list<string>
     */
    private const INLINE_CODE_FLAGS = ['-r', '-e', '-E', '-c', '-p', '--eval', '--exec', '--print', '--command', '--code', '--run'];

    /** @var list<string> */
    private const REQUIRED_KEYS = [
        'program', 'arguments', 'phases', 'working_directory',
        'success_exit_codes', 'side_effects', 'network', 'mutates',
    ];

    /** @param array<string, CheckProfile> $profiles */
    private function __construct(private array $profiles) {}

    public static function fromConfiguredValues(?ProcessPolicyRegistry $policies = null): self
    {
        $configured = config('ai6.checks.profiles');
        if (! is_array($configured) || $configured === []) {
            throw new CheckProfileConfigurationException('check_profiles_missing', 'The trusted check profile definitions are missing.');
        }

        $allowedExecutables = ($policies ?? ProcessPolicyRegistry::fromConfiguredValues())
            ->get(ProcessPolicyName::CHECKER)->allowedExecutables;

        $profiles = [];
        foreach ($configured as $name => $definition) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $name) !== 1) {
                throw new CheckProfileConfigurationException('check_profile_name_invalid', 'A check profile name is invalid.');
            }
            $profiles[$name] = self::profile($name, $definition, $allowedExecutables);
        }

        ksort($profiles);

        return new self($profiles);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->profiles);
    }

    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    public function get(string $name): CheckProfile
    {
        return $this->profiles[$name]
            ?? throw new CheckProfileConfigurationException('check_profile_unknown', 'The requested check profile is not server-defined.');
    }

    /** @param list<string> $allowedExecutables */
    private static function profile(string $name, mixed $definition, array $allowedExecutables): CheckProfile
    {
        if (is_string($definition)) {
            throw new CheckProfileConfigurationException('check_profile_shell_string', 'A check profile must be an argument list, never a shell string.');
        }
        $keys = is_array($definition) ? array_keys($definition) : null;
        if ($keys === null || array_is_list($definition) || count($keys) !== count(self::REQUIRED_KEYS)
            || array_diff(self::REQUIRED_KEYS, $keys) !== []) {
            throw new CheckProfileConfigurationException('check_profile_metadata_missing', 'A check profile misses its declared program, phases or metadata.');
        }

        // An interior space is legitimate in an installation path, so it is not
        // rejected here: the strict allowlist match below is what makes an
        // embedded argument impossible, and a control character never belongs
        // in a program path at all.
        $program = $definition['program'];
        if (! is_string($program) || trim($program) === '' || preg_match('/[\x00-\x1F\x7F]/', $program) === 1) {
            throw new CheckProfileConfigurationException('check_profile_program_invalid', 'A check profile program must be a single control-character-free path.');
        }
        if (in_array(strtolower(basename(str_replace('\\', '/', $program))), self::SHELL_INTERPRETERS, true)) {
            throw new CheckProfileConfigurationException('check_profile_shell_string', 'A check profile must not run a shell interpreter.');
        }
        if (! in_array($program, $allowedExecutables, true)) {
            throw new CheckProfileConfigurationException('check_profile_program_unknown', 'A check profile program is not allowed by the checker process policy.');
        }

        $arguments = $definition['arguments'];
        if (! is_array($arguments) || ! array_is_list($arguments)) {
            throw new CheckProfileConfigurationException('check_profile_shell_string', 'A check profile must declare its arguments as a list.');
        }
        foreach ($arguments as $argument) {
            if (! is_string($argument) || $argument === '' || str_contains($argument, "\x00")) {
                throw new CheckProfileConfigurationException('check_profile_argument_invalid', 'A check profile argument is not a plain non-empty string.');
            }
            if (in_array($argument, self::INLINE_CODE_FLAGS, true)) {
                throw new CheckProfileConfigurationException('check_profile_inline_code', 'A check profile must not hand its program inline code.');
            }
        }

        $phases = $definition['phases'];
        if (! is_array($phases) || ! array_is_list($phases) || $phases === []) {
            throw new CheckProfileConfigurationException('check_profile_phases_invalid', 'A check profile must declare at least one phase.');
        }
        $resolved = [];
        foreach ($phases as $phase) {
            $case = is_string($phase) ? CheckPhase::tryFrom($phase) : null;
            if (! $case instanceof CheckPhase || in_array($case, $resolved, true)) {
                throw new CheckProfileConfigurationException('check_profile_phases_invalid', 'A check profile declares an unknown or duplicate phase.');
            }
            $resolved[] = $case;
        }

        $workingDirectory = is_string($definition['working_directory'])
            ? CheckProfileWorkingDirectory::tryFrom($definition['working_directory'])
            : null;
        if (! $workingDirectory instanceof CheckProfileWorkingDirectory) {
            throw new CheckProfileConfigurationException('check_profile_working_directory_invalid', 'A check profile declares an unknown working directory.');
        }

        $exitCodes = $definition['success_exit_codes'];
        if (! is_array($exitCodes) || ! array_is_list($exitCodes) || $exitCodes === []) {
            throw new CheckProfileConfigurationException('check_profile_exit_codes_invalid', 'A check profile must declare its successful exit codes.');
        }
        foreach ($exitCodes as $exitCode) {
            if (! is_int($exitCode) || $exitCode < 0 || $exitCode > 255) {
                throw new CheckProfileConfigurationException('check_profile_exit_codes_invalid', 'A check profile declares an invalid successful exit code.');
            }
        }

        foreach (['side_effects', 'network', 'mutates'] as $flag) {
            if (! is_bool($definition[$flag])) {
                throw new CheckProfileConfigurationException('check_profile_metadata_missing', 'A check profile misses a declared side effect, network or mutation flag.');
            }
        }

        return new CheckProfile(
            $name,
            $program,
            $arguments,
            $resolved,
            $workingDirectory,
            array_values(array_unique($exitCodes)),
            $definition['side_effects'],
            $definition['network'],
            $definition['mutates'],
        );
    }
}
