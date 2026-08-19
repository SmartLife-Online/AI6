<?php

namespace App\AI6\Runs;

/**
 * The server-decided category of a changed path (TKT-007, AGT-009).
 *
 * A path never decides its own category: the category comes exclusively from
 * its position in the repository and the freigegebene project configuration,
 * never from ticket content, provider text or the path's own bytes.
 */
enum ScopeCategory: string
{
    /** Deterministically low-risk; may be added to the effective scope without a human decision. */
    case AUTO_ALLOW = 'auto_allow';

    /** Always requires a human decision; never auto-added regardless of project configuration. */
    case SENSITIVE = 'sensitive';

    /** Neither auto-allow nor a fixed sensitive category; requires a human decision. */
    case UNDETERMINED = 'undetermined';

    public function requiresHumanDecision(): bool
    {
        return $this !== self::AUTO_ALLOW;
    }
}
