<?php

namespace App\AI6\Checks;

use RuntimeException;

/**
 * A check may only execute inside the checker role while its isolation control
 * is active.
 *
 * Outside that role the promises of `AGT-007`, `GIT-010` and `SEC-005` do not
 * hold: the worker carries the managed clone and its deploy keys and has normal
 * network access, while a check profile executes the managed project's own
 * untrusted code (`SEC-007`). The refusal is therefore fail closed and never a
 * green result.
 */
final class CheckExecutionRoleRequired extends RuntimeException
{
    public function __construct(public readonly string $runtimeRole)
    {
        parent::__construct('A check may only execute inside the isolated checker role.');
    }
}
