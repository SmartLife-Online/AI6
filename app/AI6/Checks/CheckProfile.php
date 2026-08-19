<?php

namespace App\AI6\Checks;

/**
 * One server-defined check profile.
 *
 * Program, arguments, phases and the side effect, network and mutation
 * metadata all come from trusted server configuration (`CFG-003`). A managed
 * project selects a profile by name and can define none of these values.
 */
final readonly class CheckProfile
{
    /**
     * @param  list<string>  $arguments
     * @param  list<CheckPhase>  $phases
     * @param  list<int>  $successExitCodes
     */
    public function __construct(
        public string $name,
        public string $program,
        public array $arguments,
        public array $phases,
        public CheckProfileWorkingDirectory $workingDirectory,
        public array $successExitCodes,
        public bool $hasSideEffects,
        public bool $requiresNetwork,
        public bool $mutatesTree,
    ) {}

    public function allowsPhase(CheckPhase $phase): bool
    {
        return in_array($phase, $this->phases, true);
    }

    /** @return non-empty-list<string> */
    public function command(): array
    {
        return [$this->program, ...$this->arguments];
    }

    /** @return array{side_effects: bool, network: bool, mutates: bool} */
    public function declaredMetadata(): array
    {
        return [
            'side_effects' => $this->hasSideEffects,
            'network' => $this->requiresNetwork,
            'mutates' => $this->mutatesTree,
        ];
    }
}
