<?php

namespace App\AI6\Shared\Doctor;

use JsonException;

final readonly class CheckerRuntimeDoctorCheck implements DoctorCheck
{
    public function label(): string
    {
        return 'Checker-Laufzeit';
    }

    public function run(): DoctorCheckResult
    {
        $root = config('ai6.execution_mailboxes.checker_output_root');
        $input = config('ai6.execution_mailboxes.checker_root');
        if (! is_string($root) || ! is_dir($root) || is_link($root) || ! is_string($input) || ! is_dir($input) || is_link($input)) {
            return new DoctorCheckResult(false, ['Fehler' => 'checker_root_unavailable']);
        }
        $path = rtrim($root, '/\\').'/attestations/checker.json';
        if (! is_file($path) || is_link($path)) {
            return new DoctorCheckResult(false, ['Fehler' => 'checker_attestation_missing']);
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new DoctorCheckResult(false, ['Fehler' => 'checker_attestation_invalid']);
        }
        $keys = ['schema', 'checker_boot_id', 'recorded_at', 'role', 'input_read_only', 'output_separate', 'workspace_private', 'container_read_only', 'network_isolated', 'namespace_tooling', 'profiles_executable', 'profile_programs'];
        if (! is_array($document) || array_keys($document) !== $keys
            || $document['schema'] !== 'ai6.checker-attestation.v1' || $document['role'] !== 'checker'
            || ! is_string($document['checker_boot_id']) || preg_match('/\A[0-9a-f]{32}\z/D', $document['checker_boot_id']) !== 1
            || ! is_int($document['recorded_at'])
            || time() - $document['recorded_at'] > (int) config('ai6.checks.runtime.attestation_max_age_seconds', 15)) {
            return new DoctorCheckResult(false, ['Fehler' => 'checker_attestation_stale_or_invalid']);
        }
        foreach (array_slice($keys, 4, 7) as $promise) {
            if (($document[$promise] ?? null) !== true) {
                return new DoctorCheckResult(false, ['Fehler' => 'checker_attestation_'.$promise]);
            }
        }
        if (! is_array($document['profile_programs']) || $document['profile_programs'] === []) {
            return new DoctorCheckResult(false, ['Fehler' => 'checker_attestation_profile_programs']);
        }
        foreach ($document['profile_programs'] as $name => $executable) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $name) !== 1 || $executable !== true) {
                return new DoctorCheckResult(false, ['Fehler' => 'checker_attestation_profile_program_unavailable']);
            }
        }

        return new DoctorCheckResult(true, ['Boot-ID' => $document['checker_boot_id'], 'Status' => 'frisch und vollständig']);
    }
}
