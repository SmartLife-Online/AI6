<?php

namespace App\AI6\Agents;

use JsonSerializable;

final readonly class InstructionSnapshot implements JsonSerializable
{
    /** @param list<InstructionSnapshotEntry> $entries */
    public function __construct(
        public string $providerProfileAlias,
        public array $entries,
        public string $hash,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider_profile_alias' => $this->providerProfileAlias,
            'entries' => array_map(
                static fn (InstructionSnapshotEntry $entry): array => $entry->jsonSerialize(),
                $this->entries,
            ),
            'instruction_snapshot_hash' => $this->hash,
        ];
    }
}
