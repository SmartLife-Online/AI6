<?php

namespace App\AI6\Shared;

use App\AI6\Shared\Doctor\DoctorCommand;
use App\AI6\Shared\Doctor\RedactionKeyringDoctorCheck;
use App\AI6\Shared\Doctor\SecurityPolicyDoctorCheck;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionKeyringFactory;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityPolicyFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class AI6ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SecurityPolicyFactory::class);
        $this->app->singleton(
            SecurityPolicy::class,
            static fn (Application $app): SecurityPolicy => $app->make(SecurityPolicyFactory::class)->fromConfiguredValues(),
        );
        $this->app->singleton(RedactionKeyringFactory::class);
        $this->app->singleton(
            RedactionKeyring::class,
            static fn (Application $app): RedactionKeyring => $app->make(RedactionKeyringFactory::class)->fromConfiguredValues(),
        );
        $this->app->singleton(
            RedactionPolicy::class,
            static fn (): RedactionPolicy => new RedactionPolicy(RedactionRuleSet::defaults()),
        );
        $this->app->singleton(
            RedactionFingerprintGenerator::class,
            static fn (Application $app): RedactionFingerprintGenerator => new RedactionFingerprintGenerator(
                $app->make(RedactionKeyring::class),
            ),
        );
        $this->app->singleton(
            Redactor::class,
            static fn (Application $app): Redactor => new Redactor(
                $app->make(RedactionPolicy::class),
                $app->make(RedactionFingerprintGenerator::class),
            ),
        );
        $this->app->singleton(
            DoctorCommand::class,
            static fn (Application $app): DoctorCommand => new DoctorCommand([
                new SecurityPolicyDoctorCheck($app->make(SecurityPolicy::class)),
                new RedactionKeyringDoctorCheck($app->make(RedactionKeyringFactory::class)),
            ]),
        );

        $this->app->make(SecurityPolicy::class);

        if (! $this->mayBootstrapWithoutRedactionKeyring()) {
            $this->app->make(RedactionKeyring::class);
        }
    }

    private function mayBootstrapWithoutRedactionKeyring(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $arguments = $_SERVER['argv'] ?? [];
        $command = null;
        $skipOptionValue = false;

        if (is_array($arguments)) {
            foreach (array_slice($arguments, 1) as $argument) {
                if (! is_string($argument)) {
                    continue;
                }

                if ($skipOptionValue) {
                    $skipOptionValue = false;

                    continue;
                }

                if ($argument === '--env') {
                    $skipOptionValue = true;

                    continue;
                }

                if (! str_starts_with($argument, '-')) {
                    $command = $argument;

                    break;
                }
            }
        }

        if (in_array($command, ['key:generate', 'package:discover', 'test'], true)) {
            return true;
        }

        return $command === 'migrate' && config('ai6.runtime_role') === 'init';
    }
}
