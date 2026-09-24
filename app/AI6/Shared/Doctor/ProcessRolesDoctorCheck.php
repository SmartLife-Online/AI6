<?php

namespace App\AI6\Shared\Doctor;

use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Git\ControlOperationRuntimeIdentityFactory;
use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use Throwable;

final readonly class ProcessRolesDoctorCheck implements DoctorCheck
{
    public function __construct(private ControlOperationRuntimeIdentityFactory $identityFactory) {}

    public function label(): string
    {
        return 'Prozessrollen';
    }

    public function run(): DoctorCheckResult
    {
        $identity = $this->identityFactory->fromConfiguredValues();
        $role = $identity->runtimeRole;
        $details = ['Rolle' => $role === '' ? 'unbekannt' : $role];
        $passed = true;

        if (! in_array($role, ['worker', 'scheduler', 'agent', 'checker'], true)
            || $identity->heartbeatDirectory === ''
            || ! is_string(getenv('AI6_HEARTBEAT_MAX_AGE'))
            || preg_match('/\A[1-9][0-9]*\z/D', (string) getenv('AI6_HEARTBEAT_MAX_AGE')) !== 1
        ) {
            $details['Heartbeat'] = 'FEHLER (Rolle '.$this->safeRole($role).')';
            $passed = false;
        } else {
            try {
                $status = (new RuntimeHeartbeat($identity->heartbeatDirectory))->status(
                    $role,
                    (int) getenv('AI6_HEARTBEAT_MAX_AGE'),
                );
            } catch (Throwable) {
                $status = ['healthy' => false, 'age' => null];
            }
            $age = $status['age'] === null ? 'unbekannt' : $status['age'].'s';
            $details['Heartbeat'] = ($status['healthy'] ? 'OK' : 'FEHLER').' (Alter '.$age.')';
            if (! $status['healthy']) {
                $passed = false;
            }
        }

        try {
            // ProviderCapabilityReport depends on the redaction boundary. Resolve it
            // only when this optional role check actually runs, so Laravel can still
            // discover console commands such as `migrate --help` before the keyring
            // bootstrap is available.
            app(ProviderCapabilityReport::class)->boot();
            $details['Agentpräsenz'] = 'OK (Lebendigkeit)';
        } catch (Throwable) {
            $details['Agentpräsenz'] = 'FEHLER (Rolle agent)';
            $passed = false;
        }

        foreach (['scheduler', 'app'] as $unobservableRole) {
            if ($unobservableRole !== $role) {
                $details['Rolle '.$unobservableRole] = 'UNGEPRÜFT (ai6:runtime-health --role='.$unobservableRole.')';
            }
        }

        return new DoctorCheckResult($passed, $details);
    }

    private function safeRole(string $role): string
    {
        return $role === '' ? 'unbekannt' : $role;
    }
}
