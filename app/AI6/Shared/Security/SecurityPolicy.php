<?php

namespace App\AI6\Shared\Security;

use InvalidArgumentException;

final readonly class SecurityPolicy
{
    /** @var array<string, bool> */
    private array $measureStates;

    private string $policyHash;

    /** @param array<string, bool> $measureStates */
    public function __construct(
        public SecurityProfile $profile,
        array $measureStates,
        public bool $reducedModeAcknowledged,
    ) {
        $expectedKeys = array_map(
            static fn (SecurityMeasure $measure): string => $measure->value,
            SecurityMeasure::cases(),
        );
        $actualKeys = array_keys($measureStates);
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw new InvalidArgumentException('SecurityPolicy requires exactly one state for every SecurityMeasure.');
        }

        $ordered = [];
        foreach (SecurityMeasure::cases() as $measure) {
            $ordered[$measure->value] = $measureStates[$measure->value];
        }

        $this->measureStates = $ordered;
        $this->policyHash = SecurityPolicyHasher::hash(
            $this->profile,
            $this->measureStates,
            $this->reducedModeAcknowledged,
        );
    }

    public function isEnabled(SecurityMeasure $measure): bool
    {
        return $this->measureStates[$measure->value];
    }

    /** @return array<string, bool> */
    public function measures(): array
    {
        return $this->measureStates;
    }

    /** @return list<SecurityMeasure> */
    public function disabledMeasures(): array
    {
        return array_values(array_filter(
            SecurityMeasure::cases(),
            fn (SecurityMeasure $measure): bool => ! $this->isEnabled($measure),
        ));
    }

    public function hash(): string
    {
        return $this->policyHash;
    }

    public function bannerData(): SecurityBannerData
    {
        return new SecurityBannerData(
            $this->profile,
            $this->disabledMeasures(),
            $this->reducedModeAcknowledged,
        );
    }
}
