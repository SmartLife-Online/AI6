<?php

namespace App\AI6\Git;

use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use RuntimeException;

final readonly class CanonicalDiffHasher
{
    private const DOMAIN = 'AI6-RUN-DIFF-V1';

    public function __construct(private CanonicalJson $json, private Redactor $redactor) {}

    public function fromRaw(string $raw, RedactionContext $context): CanonicalDiff
    {
        // The UTF-8/redaction gate runs before any parsing or canonicalization.
        $redacted = $this->redactor->redact($raw, $context)->text;
        if ($raw === '') {
            $encoded = $this->json->normalizeAndEncode([]);

            return new CanonicalDiff([], hash('sha256', self::DOMAIN."\0".$encoded), $redacted);
        }
        if (! str_ends_with($raw, "\0")) {
            throw new RuntimeException('The Git raw diff is malformed.');
        }

        $entries = [];
        $records = explode("\0", substr($raw, 0, -1));
        if (count($records) % 2 !== 0) {
            throw new RuntimeException('The Git raw diff is malformed.');
        }
        for ($index = 0; $index < count($records); $index += 2) {
            $metadata = $records[$index];
            $path = $records[$index + 1];
            if (preg_match('/\A:([0-7]{6}) ([0-7]{6}) ([0-9a-f]{64}) ([0-9a-f]{64}) ([ACDMRTUXB][0-9]{0,3})\z/D', $metadata, $matches) !== 1
                || ! $this->canonicalPath($path)) {
                throw new RuntimeException('The Git raw diff contains an unsafe entry.');
            }
            $entries[] = [
                'old_mode' => $matches[1], 'new_mode' => $matches[2], 'old_oid' => $matches[3], 'new_oid' => $matches[4],
                'status' => $matches[5], 'path' => $path,
            ];
        }
        usort($entries, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']) ?: strcmp($left['status'], $right['status']));
        $encoded = $this->json->normalizeAndEncode($entries);

        return new CanonicalDiff($entries, hash('sha256', self::DOMAIN."\0".$encoded), $redacted);
    }

    private function canonicalPath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, "\0")
            && ! in_array('.', explode('/', $path), true) && ! in_array('..', explode('/', $path), true);
    }
}
