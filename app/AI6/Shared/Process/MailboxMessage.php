<?php

namespace App\AI6\Shared\Process;

final readonly class MailboxMessage
{
    public function __construct(
        public int $version,
        public ExecutionRole $role,
        public MailboxMessageType $type,
        public string $slotId,
        public string $deliveryId,
        public int $size,
        public string $contentHash,
        public string $content,
        public int $envelopeSize,
        public string $envelopeContentHash,
    ) {}
}
