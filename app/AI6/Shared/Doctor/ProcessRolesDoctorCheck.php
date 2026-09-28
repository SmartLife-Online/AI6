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
        $details = ['Rolle' => $this->safeRole($role)];
        $passed = true;

        if ($identity->heartbeatDirectory === '' && $role === 'app') {
            $details['Heartbeat'] = 'nicht vorgesehen (Rolle app; HTTP-Healthcheck)';
        } else {
            try {
                $status = RuntimeHeartbeat::statusFromEnvironment($role);
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
            $presence = app(ProviderCapabilityReport::class)->presence();
            $details['Agentpräsenz'] = 'OK (Lebendigkeit; Alter '.(time() - $presence['recorded_at']).'s)';
        } catch (Throwable) {
            $details['Agentpräsenz'] = 'FEHLER (Rolle agent)';
            $passed = false;
        }

        foreach (['worker', 'scheduler', 'app'] as $unobservableRole) {
            if ($unobservableRole !== $role) {
                $command = $unobservableRole === 'app'
                    ? 'docker compose exec app /opt/ai6/docker/healthcheck.sh app'
                    : 'docker compose exec '.$unobservableRole.' php artisan ai6:runtime-health --role='.$unobservableRole;
                $details['Rolle '.$unobservableRole] = 'UNGEPRÜFT ('.$command.')';
            }
        }

        return new DoctorCheckResult($passed, $details);
    }

    private function safeRole(string $role): string
    {
        return in_array($role, ['app', 'worker', 'scheduler', 'agent', 'checker'], true) ? $role : 'unbekannt';
    }
}
