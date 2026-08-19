<?php

namespace App\AI6\Runs;

/**
 * The single server-side decision what counts as an instruction path (AGT-009).
 *
 * A path never decides this through its own content; only its position in the
 * repository counts. Both the implementation turn and the contract-amendment
 * saga consult this one policy, so an instruction path can neither be added to
 * a running scope nor smuggled into a ticket's files list by an amendment.
 */
final class InstructionPathPolicy
{
    public function isInstructionPath(string $path): bool
    {
        return in_array($path, ['AGENTS.md', 'CLAUDE.md'], true)
            || str_starts_with($path, 'instructions/')
            || str_starts_with($path, '.ai6/');
    }
}
