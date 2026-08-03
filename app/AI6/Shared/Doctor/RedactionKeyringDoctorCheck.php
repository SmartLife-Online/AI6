<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Redaction\RedactionKeyringFactory;

final readonly class RedactionKeyringDoctorCheck implements DoctorCheck
{
    public function __construct(private RedactionKeyringFactory $keyringFactory) {}

    public function label(): string
    {
        return 'Redaction-Schlüsselring';
    }

    public function run(): DoctorCheckResult
    {
        try {
            $keyring = $this->keyringFactory->fromConfiguredValues();
        } catch (ConfigurationException) {
            return new DoctorCheckResult(false, [
                'Zustand' => 'ungültig oder unvollständig',
            ]);
        }

        return new DoctorCheckResult(true, [
            'Aktive Key-ID' => $keyring->activeKeyId(),
            'Fingerprintversion' => (string) $keyring->activeVersion(),
            'Konfigurierte Key-IDs' => (string) count($keyring->keyIds()),
            'Schlüsselquelle' => $keyring->usesApplicationKeyFallback()
                ? 'APP_KEY-Fallback (nur lokal; nicht rotationsstabil)'
                : 'expliziter Schlüsselring',
        ]);
    }
}
