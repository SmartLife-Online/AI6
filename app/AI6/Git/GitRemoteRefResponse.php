<?php

namespace App\AI6\Git;

final class GitRemoteRefResponse
{
    public static function oid(string $output, string $ref): string
    {
        if (preg_match('/\A([0-9a-f]+)\t'.preg_quote($ref, '/').'\r?\n\z/D', $output, $matches) !== 1
            || GitObjectFormat::tryFromOid($matches[1]) === null) {
            throw new ControlRemoteRefUnresolved('The remote probe returned a malformed or non-unique ref binding.');
        }

        return $matches[1];
    }
}
