<?php

namespace App\AI6\Shared\Redaction;

use JsonSerializable;

final readonly class RedactionMatch implements JsonSerializable
{
    public function __construct(
        public RedactionMatchType $type,
        public string $field,
        public int $start,
        public int $length,
        public string $marker,
        public int $fingerprintVersion,
        public string $keyId,
        public string $fingerprint,
    ) {}

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type->value,
            'field' => $this->field,
            'start' => $this->start,
            'length' => $this->length,
            'marker' => $this->marker,
            'fingerprint_version' => $this->fingerprintVersion,
            'key_id' => $this->keyId,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
