<?php

namespace App\AI6\Checks;

/**
 * Where a profile's process is started.
 *
 * `TREE` is the exported project tree itself. `BATCH` is its parent, which also
 * holds the always empty `baseline` directory a repo-less `git diff --no-index`
 * needs as its left-hand side. Both are server-owned; a project cannot pick one.
 */
enum CheckProfileWorkingDirectory: string
{
    case TREE = 'tree';
    case BATCH = 'batch';
}
