<?php

namespace App\AI6\Tickets;

use App\AI6\Shared\Config\ConfigurationException;

final readonly class DependencySatisfiedStatusAllowlist
{
    /** @var list<string> */
    private array $statuses;

    /** @param list<string> $statuses */
    public function __construct(array $statuses)
    {
        $statuses = array_values(array_unique($statuses));

        if ($statuses === []) {
            throw new ConfigurationException('The dependency-satisfied status allowlist must not be empty.');
        }

        foreach ($statuses as $status) {
            if (TicketStatus::tryFrom($status) === null) {
                throw new ConfigurationException('The dependency-satisfied status allowlist contains an unknown status.');
            }
        }

        $this->statuses = $statuses;
    }

    public static function fromConfiguredValues(): self
    {
        $statuses = config('ai6.project_config.dependency_satisfied_status_allowlist');

        return new self(is_array($statuses) ? array_values(array_filter($statuses, 'is_string')) : []);
    }

    public function allows(string $status): bool
    {
        return in_array($status, $this->statuses, true);
    }
}
