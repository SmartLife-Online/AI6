<?php

namespace App\AI6\Reviews;

final class ExactFindingGroup
{
    /** @param list<string> $criterionRefs */
    public static function key(
        FindingSeverity $severity,
        FindingOriginalDisposition $disposition,
        FindingCategory $category,
        string $file,
        int $line,
        string $title,
        string $evidence,
        string $expectedResult,
        array $criterionRefs,
    ): string {
        sort($criterionRefs, SORT_STRING);

        return hash('sha256', json_encode([
            $severity->value,
            $disposition->value,
            $category->value,
            $file,
            $line,
            $title,
            $evidence,
            $expectedResult,
            $criterionRefs,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
