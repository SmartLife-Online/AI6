<?php

namespace App\AI6\Shared\Redaction;

final readonly class RedactionFingerprint
{
    public function __construct(
        public int $version,
        public string $keyId,
        public string $value,
    ) {}
}
