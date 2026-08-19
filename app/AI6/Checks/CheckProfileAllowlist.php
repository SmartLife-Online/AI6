<?php

namespace App\AI6\Checks;

/**
 * The allowed check profile names of an untrusted project configuration.
 *
 * The names are never a list of their own: they are derived from the one
 * CheckProfileRegistry that also defines what each profile executes, so
 * validation and execution can never disagree about which names exist.
 */
final readonly class CheckProfileAllowlist
{
    /** @var list<string> */
    private array $profiles;

    /** @param list<string> $profiles */
    public function __construct(array $profiles)
    {
        $this->profiles = array_values(array_unique($profiles));
    }

    public static function fromRegistry(CheckProfileRegistry $registry): self
    {
        return new self($registry->names());
    }

    public static function fromConfiguredValues(): self
    {
        return self::fromRegistry(CheckProfileRegistry::fromConfiguredValues());
    }

    public function allows(string $profile): bool
    {
        return in_array($profile, $this->profiles, true);
    }

    /** @return list<string> */
    public function profiles(): array
    {
        return $this->profiles;
    }
}
