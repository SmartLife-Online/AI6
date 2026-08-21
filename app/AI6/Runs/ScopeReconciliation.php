<?php

namespace App\AI6\Runs;

final readonly class ScopeReconciliation
{
    /**
     * @param  list<string>  $changedPaths
     * @param  list<string>  $effectiveScope
     * @return list<string>
     */
    public function unresolved(array $changedPaths, array $effectiveScope): array
    {
        $unresolved = [];
        foreach (array_values(array_unique($changedPaths)) as $path) {
            if (! ScopePathMatcher::coveredBy($path, $effectiveScope)) {
                $unresolved[] = $path;
            }
        }
        sort($unresolved, SORT_STRING);

        return $unresolved;
    }
}
