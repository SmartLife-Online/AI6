<?php

namespace App\AI6\Git;

use App\AI6\Shared\Config\ConfigurationException;

final class TicketMutationConfigurationFactory
{
    public function fromConfiguredValues(): TicketMutationConfiguration
    {
        $name = config('ai6.ticket_mutations.git_author_name');
        $email = config('ai6.ticket_mutations.git_author_email');

        if (! is_string($name) || trim($name) === '' || strlen($name) > 200 || str_contains($name, "\n")
            || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254
            || str_contains($email, "\n")) {
            throw new ConfigurationException('The trusted ticket-mutation Git author identity is invalid.');
        }

        return new TicketMutationConfiguration(trim($name), $email);
    }
}
