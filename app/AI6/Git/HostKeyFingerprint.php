<?php

namespace App\AI6\Git;

final readonly class HostKeyFingerprint
{
    private function __construct(private string $digest) {}

    /** @return array{fingerprint: self|null, failure_class: string|null} */
    public static function parse(string $value): array
    {
        if (preg_match('/\A(?i:SHA256):([A-Za-z0-9+\/]{43})(=?)\z/D', $value, $matches) !== 1) {
            return ['fingerprint' => null, 'failure_class' => 'format_error'];
        }

        $payload = $matches[1];
        $digest = base64_decode($payload.'=', true);

        if (! is_string($digest)
            || strlen($digest) !== 32
            || rtrim(base64_encode($digest), '=') !== $payload) {
            return ['fingerprint' => null, 'failure_class' => 'format_error'];
        }

        return ['fingerprint' => new self($digest), 'failure_class' => null];
    }

    public function canonical(): string
    {
        return 'SHA256:'.rtrim(base64_encode($this->digest), '=');
    }

    public function matches(self $other): bool
    {
        return hash_equals($this->digest, $other->digest);
    }
}
