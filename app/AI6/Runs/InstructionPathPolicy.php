<?php

namespace App\AI6\Runs;

/**
 * The single server-side decision what counts as an instruction path (AGT-009).
 *
 * A path never decides this through its own content; only its position in the
 * repository counts. The set is derived from the discovery forms the provider
 * profiles actually use (see {@see InstructionCandidateCollector}): the
 * repository-level `agents_md` form and the `agents_md_nested` form, which
 * resolves an AGENTS.md in every directory on the way up from a scoped file.
 * A nested instruction file is therefore an instruction path at any depth, not
 * only at the repository root.
 *
 * Both the implementation turn and the contract-amendment saga consult this one
 * policy, so an instruction path can neither be added to a running scope nor
 * smuggled into a ticket's files list by an amendment.
 */
final class InstructionPathPolicy
{
    /** Instruction file names; nested discovery makes them effective at any depth. */
    private const INSTRUCTION_BASENAMES = ['AGENTS.md', 'CLAUDE.md'];

    public function isInstructionPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        $separator = strrpos($normalized, '/');
        $basename = $separator === false ? $normalized : substr($normalized, $separator + 1);

        return in_array($basename, self::INSTRUCTION_BASENAMES, true)
            || $normalized === 'instructions'
            || str_starts_with($normalized, 'instructions/')
            || $normalized === '.ai6'
            || str_starts_with($normalized, '.ai6/');
    }
}
