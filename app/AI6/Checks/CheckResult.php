<?php

namespace App\AI6\Checks;

/**
 * The structured outcome of exactly one check execution.
 *
 * The output has already crossed the central redaction boundary; this module
 * owns no secret or pattern list of its own. `reason` names why a result is not
 * a plain success, so a failure never has to be inferred from a state that was
 * mapped onto another one.
 */
final readonly class CheckResult
{
    /** @param array{side_effects: bool, network: bool, mutates: bool} $declaredMetadata */
    public function __construct(
        public string $profile,
        public CheckPhase $phase,
        public CheckResultState $state,
        public ?int $exitCode,
        public ?string $reason,
        public int $durationMilliseconds,
        public string $redactedOutput,
        public string $treeShaBefore,
        public string $treeShaAfter,
        public array $declaredMetadata,
    ) {}

    public function succeeded(): bool
    {
        return $this->state === CheckResultState::SUCCEEDED;
    }

    /** Whether the execution changed the checked tree, regardless of its declaration. */
    public function mutatedTree(): bool
    {
        return ! hash_equals($this->treeShaBefore, $this->treeShaAfter);
    }

    /**
     * The deterministic identity of this execution.
     *
     * Run, phase, profile and the checked tree are the four coordinates a
     * repeated delivery shares, so the key rejects the duplicate instead of
     * producing a second process and a second row.
     */
    public static function key(string $runId, CheckPhase $phase, string $profile, string $treeSha): string
    {
        return hash('sha256', implode("\0", [$runId, $phase->value, $profile, $treeSha]));
    }
}
