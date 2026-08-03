<?php

namespace App\AI6\Shared\Security;

final readonly class SecurityBannerData
{
    /** @param list<SecurityMeasure> $disabledMeasures */
    public function __construct(
        public SecurityProfile $profile,
        public array $disabledMeasures,
        public bool $reducedModeAcknowledged,
    ) {}

    /** @return array{profile: string, disabled_measures: list<string>, reduced_mode_acknowledged: bool} */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile->value,
            'disabled_measures' => array_map(
                static fn (SecurityMeasure $measure): string => $measure->value,
                $this->disabledMeasures,
            ),
            'reduced_mode_acknowledged' => $this->reducedModeAcknowledged,
        ];
    }
}
