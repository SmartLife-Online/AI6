<?php

namespace App\AI6\Shared\Doctor;

use Illuminate\Support\Str;

final readonly class MailDoctorCheck implements DoctorCheck
{
    public function label(): string
    {
        return 'Mail';
    }

    public function run(): DoctorCheckResult
    {
        if (config('ai6.runtime_role') !== 'worker') {
            return new DoctorCheckResult(true, ['Zuständigkeit' => 'nicht zuständig']);
        }

        if (config('mail.default') !== 'smtp') {
            return new DoctorCheckResult(false, ['Rolle' => 'worker', 'Grund' => 'mail_transport_unsupported']);
        }

        $host = config('mail.mailers.smtp.host');
        $port = config('mail.mailers.smtp.port');
        $from = config('mail.from.address');
        $portValid = is_int($port) || (is_string($port) && preg_match('/\A[0-9]+\z/D', $port) === 1);
        $portNumber = $portValid ? (int) $port : 0;

        if (! is_string($host) || trim($host) === '') {
            return new DoctorCheckResult(false, ['Rolle' => 'worker', 'Grund' => 'mail_host_missing']);
        }
        if (! $portValid || $portNumber < 1 || $portNumber > 65535) {
            return new DoctorCheckResult(false, ['Rolle' => 'worker', 'Grund' => 'mail_port_invalid']);
        }
        if (! is_string($from) || filter_var($from, FILTER_VALIDATE_EMAIL) === false || Str::length($from) > 255) {
            return new DoctorCheckResult(false, ['Rolle' => 'worker', 'Grund' => 'mail_from_invalid']);
        }

        return new DoctorCheckResult(true, ['Rolle' => 'worker', 'Status' => 'SMTP-Konfiguration statisch geprüft']);
    }
}
