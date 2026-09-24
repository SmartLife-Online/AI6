<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Agents\SecurityReviewerProfileResolver;
use App\AI6\Shared\Config\ConfigurationException;

final readonly class SecurityReviewProfileDoctorCheck implements DoctorCheck
{
    public function __construct(private SecurityReviewerProfileResolver $resolver) {}

    public function label(): string
    {
        return 'Securityreview-Profil';
    }

    public function run(): DoctorCheckResult
    {
        try {
            $selection = $this->resolver->resolve();
        } catch (AgentProfileSelectionException $exception) {
            return new DoctorCheckResult(false, [
                'Grund' => 'security_review_profile_unresolved',
                'Auswahl' => $exception->reason->value,
            ]);
        } catch (ConfigurationException) {
            return new DoctorCheckResult(false, ['Grund' => 'security_review_profile_unresolved']);
        }

        $adapter = $selection->profile->providerProfileAlias;
        if ($adapter === 'fake' && ! in_array((string) config('app.env'), ['local', 'testing'], true)) {
            return new DoctorCheckResult(false, [
                'Profil' => $selection->profile->id,
                'Grund' => 'security_review_adapter_fake',
            ]);
        }

        return new DoctorCheckResult(true, [
            'Profil' => $selection->profile->id,
            'Adapter' => $adapter,
        ]);
    }
}
