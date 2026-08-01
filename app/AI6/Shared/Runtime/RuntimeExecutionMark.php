<?php

namespace App\AI6\Shared\Runtime;

use Normalizer;
use RuntimeException;

final class RuntimeExecutionMark
{
    public function __construct(private readonly string $directory)
    {
        if ($directory === '' || ! is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('The execution directory must be an existing regular directory.');
        }
    }

    public function claim(string $idempotencyKey): bool
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$this->digest($idempotencyKey);
        set_error_handler(static fn (): bool => true);

        try {
            $handle = fopen($path, 'x+b');
        } finally {
            restore_error_handler();
        }

        if ($handle === false) {
            if (is_file($path) && ! is_link($path)) {
                return false;
            }

            throw new RuntimeException('The execution mark could not be created.');
        }

        try {
            if (fwrite($handle, "claimed\n") === false || ! fflush($handle)) {
                throw new RuntimeException('The execution mark could not be persisted.');
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    public function digest(string $idempotencyKey): string
    {
        $normalized = Normalizer::normalize($idempotencyKey, Normalizer::FORM_C);

        if ($normalized === false) {
            throw new RuntimeException('The idempotency key is not valid Unicode.');
        }

        return hash('sha256', $normalized);
    }
}
