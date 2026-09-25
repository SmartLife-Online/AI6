<?php

namespace App\AI6\Git;

use InvalidArgumentException;

enum GitObjectFormat: string
{
    case SHA1 = 'sha1';
    case SHA256 = 'sha256';

    public function length(): int
    {
        return $this === self::SHA1 ? 40 : 64;
    }

    public function zeroOid(): string
    {
        return str_repeat('0', $this->length());
    }

    public function validOid(?string $oid, bool $allowZero = false): bool
    {
        return $oid !== null
            && preg_match('/\A[0-9a-f]{'.$this->length().'}\z/D', $oid) === 1
            && ($allowZero || $oid !== $this->zeroOid());
    }

    public static function tryFromOid(?string $oid, bool $allowZero = false): ?self
    {
        $format = match (strlen($oid ?? '')) {
            40 => self::SHA1,
            64 => self::SHA256,
            default => null,
        };

        return $format?->validOid($oid, $allowZero) === true ? $format : null;
    }

    public static function fromOid(string $oid): self
    {
        return self::tryFromOid($oid) ?? throw new InvalidArgumentException('The Git object identifier is invalid.');
    }

    public function objectId(string $type, string $content): string
    {
        if (! in_array($type, ['blob', 'tree', 'commit', 'tag'], true)) {
            throw new InvalidArgumentException('The Git object type is invalid.');
        }

        return hash($this->value, $type.' '.strlen($content)."\0".$content);
    }
}
