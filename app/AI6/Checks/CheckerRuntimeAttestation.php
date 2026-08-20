<?php

namespace App\AI6\Checks;

use App\AI6\Shared\Process\ProcessIsolationVerifier;
use RuntimeException;

final readonly class CheckerRuntimeAttestation
{
    public function __construct(
        private ProcessIsolationVerifier $isolation,
        private CheckProfileRegistry $profiles,
    ) {}

    public function publish(string $bootId): void
    {
        $promises = $this->isolation->checkerRuntimePromises();
        $profilePrograms = [];
        foreach ($this->profiles->names() as $name) {
            $program = $this->profiles->get($name)->program;
            $profilePrograms[$name] = is_file($program) && ! is_link($program) && is_executable($program);
        }
        $root = config('ai6.execution_mailboxes.checker_output_root');
        if (! is_string($root) || ! is_dir($root) || is_link($root)) {
            throw new RuntimeException('checker_attestation_output_unavailable');
        }
        $directory = rtrim($root, '/\\').'/attestations';
        $created = ! is_dir($directory);
        if ($created && ! mkdir($directory, 01730, true)) {
            throw new RuntimeException('checker_attestation_output_unavailable');
        }
        if ($created && ! chmod($directory, 01730)) {
            throw new RuntimeException('checker_attestation_output_unavailable');
        }
        $bytes = json_encode([
            'schema' => 'ai6.checker-attestation.v1', 'checker_boot_id' => $bootId,
            'recorded_at' => time(), 'role' => config('ai6.runtime_role'),
            ...$promises,
            'profiles_executable' => ! in_array(false, $profilePrograms, true),
            'profile_programs' => $profilePrograms,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $directory.'/checker.'.bin2hex(random_bytes(6)).'.tmp';
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
            || ! chmod($temporary, 0640)
            || ! rename($temporary, $directory.'/checker.json')) {
            @unlink($temporary);
            throw new RuntimeException('checker_attestation_publish_failed');
        }
    }
}
