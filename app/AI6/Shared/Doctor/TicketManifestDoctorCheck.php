<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;

final readonly class TicketManifestDoctorCheck implements DoctorCheck
{
    public function __construct(
        private string $root = '',
    ) {}

    public function label(): string
    {
        return 'Ticketmanifest';
    }

    public function run(): DoctorCheckResult
    {
        $root = $this->root !== '' ? $this->root : base_path();
        $plan = $root.'/docs/AI6_IMPLEMENTATION_PLAN.md';
        $generator = $root.'/scripts/generate-ticket-manifest.php';
        $manifest = $root.'/docs/AI6_TICKET_MANIFEST.yaml';
        if (! is_file($plan) || is_link($plan) || ! is_file($generator) || is_link($generator) || ! is_file($manifest) || is_link($manifest)) {
            return new DoctorCheckResult(false, ['Fehler' => 'manifest_source_unavailable']);
        }

        $result = app(ControlProcessRunner::class)->run(new ProcessRequest(
            [PHP_BINARY, $generator, '--check', '--plan='.$plan, '--output='.$manifest],
            $root,
            [],
            [],
            new RedactionContext('doctor', null, 'ticket-manifest'),
        ));

        if (! $result->succeeded()) {
            return new DoctorCheckResult(false, [
                'Fehler' => 'manifest_drift',
                'Exitcode' => (string) ($result->exitCode ?? 1),
            ]);
        }

        return new DoctorCheckResult(true, ['Status' => 'Manifest ist aktuell']);
    }
}
