<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;

final readonly class SecurityPolicyDoctorCheck implements DoctorCheck
{
    public function __construct(private SecurityPolicy $policy) {}

    public function label(): string
    {
        return 'SecurityPolicy';
    }

    public function run(): DoctorCheckResult
    {
        $details = [
            'Profil' => $this->policy->profile->value,
            'Policyhash' => $this->policy->hash(),
        ];

        foreach (SecurityMeasure::cases() as $measure) {
            $details['Maßnahme '.$measure->value] = $this->policy->isEnabled($measure) ? 'aktiv' : 'deaktiviert';
        }

        $details['Reduktionsbestätigung'] = $this->policy->reducedModeAcknowledged ? 'bestätigt' : 'nicht bestätigt';

        return new DoctorCheckResult(true, $details);
    }
}
