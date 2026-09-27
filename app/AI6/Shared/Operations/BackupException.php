<?php

namespace App\AI6\Shared\Operations;

use RuntimeException;

/** Value-free reasons; destination paths must pass through redaction on output. */
final class BackupException extends RuntimeException
{
    /** @param array<string, array{label: string, previous: string, state: string}> $paths */
    public function __construct(
        public readonly string $reason,
        public readonly array $paths = [],
        public readonly ?string $backupTarget = null,
    ) {
        parent::__construct($reason);
    }
}
