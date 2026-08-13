<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final readonly class InstructionProfileRegistry
{
    /** @var array<string, InstructionResolutionProfile> */
    private array $profiles;

    public function __construct(StrictPositiveIntegerParser $integers)
    {
        $configured = config('ai6.instruction_profiles');
        if (! is_array($configured) || $configured === [] || array_is_list($configured)) {
            throw new ConfigurationException('Configuration key ai6.instruction_profiles must be a non-empty mapping.');
        }

        $profiles = [];
        foreach ($configured as $alias => $entries) {
            if (! is_string($alias) || ! is_array($entries) || ! array_is_list($entries) || $entries === []) {
                throw new ConfigurationException('Configuration key ai6.instruction_profiles contains an invalid profile.');
            }
            $discoveries = [];
            foreach ($entries as $index => $entry) {
                $key = 'ai6.instruction_profiles.'.$alias.'.'.$index;
                if (! is_array($entry) || array_is_list($entry) || array_keys($entry) !== ['name', 'priority', 'scope']) {
                    throw new ConfigurationException('Configuration key '.$key.' must contain the canonical discovery fields.');
                }
                if (! is_string($entry['name']) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $entry['name']) !== 1) {
                    throw new ConfigurationException('Configuration key '.$key.'.name is invalid.');
                }
                if (isset($discoveries[$entry['name']])) {
                    throw new ConfigurationException('Configuration key '.$key.'.name is duplicated.');
                }
                if (! is_string($entry['scope']) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $entry['scope']) !== 1) {
                    throw new ConfigurationException('Configuration key '.$key.'.scope is invalid.');
                }
                $priority = $integers->parse($key.'.priority', $entry['priority'], PHP_INT_MAX);
                if ($priority instanceof ConfigurationViolation) {
                    throw new ConfigurationException($priority->message);
                }
                $discoveries[$entry['name']] = new InstructionDiscovery($entry['name'], $priority, $entry['scope']);
            }
            $profiles[$alias] = new InstructionResolutionProfile($alias, $discoveries);
        }
        ksort($profiles, SORT_STRING);
        $this->profiles = $profiles;
    }

    public function get(string $providerProfileAlias): InstructionResolutionProfile
    {
        if (! isset($this->profiles[$providerProfileAlias])) {
            throw new InstructionResolutionException(InstructionResolutionError::PROFILE_UNKNOWN);
        }

        return $this->profiles[$providerProfileAlias];
    }
}
