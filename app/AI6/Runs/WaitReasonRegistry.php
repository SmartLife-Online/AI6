<?php

namespace App\AI6\Runs;

final class WaitReasonRegistry
{
    /** @var array<string, array{producer: string, resolvers: list<string>, cancellable: bool}> */
    private array $registrations = [];

    /** @param list<string> $resolvers */
    public function register(WaitReason $reason, string $producer, array $resolvers = [], bool $cancellable = false): void
    {
        if ($producer === '' || ($resolvers === [] && ! $cancellable)) {
            throw new RunTransitionConflict('unpaired_wait_reason_producer', 'A wait reason producer needs an authorized resolver or an explicit cancel path.');
        }

        $registration = ['producer' => $producer, 'resolvers' => array_values(array_unique($resolvers)), 'cancellable' => $cancellable];
        if (isset($this->registrations[$reason->value]) && $this->registrations[$reason->value] !== $registration) {
            throw new RunTransitionConflict('wait_reason_registration_conflict', 'The wait reason is already registered with another contract.');
        }
        $this->registrations[$reason->value] = $registration;
    }

    public function isRegistered(WaitReason $reason): bool
    {
        return isset($this->registrations[$reason->value]);
    }
}
