<?php

namespace App\AI6\Checks;

final readonly class CheckProfileAllowlist
{
    /** @var list<string> */
    private array $profiles;

    /** @param list<string> $profiles */
    public function __construct(array $profiles)
    {
        $this->profiles = array_values(array_unique($profiles));
    }

    public static function fromConfiguredValues(): self
    {
        $profiles = config('ai6.project_config.check_profiles');

        return new self(is_array($profiles) ? array_values(array_filter($profiles, 'is_string')) : []);
    }

    public function allows(string $profile): bool
    {
        return in_array($profile, $this->profiles, true);
    }
}
