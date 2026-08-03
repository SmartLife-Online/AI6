<?php

namespace App\AI6\Shared\Security;

use InvalidArgumentException;
use Normalizer;

final class CanonicalByteFrame
{
    /**
     * @param  list<string>  $fields
     */
    public static function encode(string $domain, array $fields): string
    {
        if ($domain === '' || preg_match('/\A[\x20-\x7E]+\z/D', $domain) !== 1) {
            throw new InvalidArgumentException('The byte-frame domain must be non-empty printable ASCII.');
        }

        $bytes = $domain."\0";

        foreach ($fields as $field) {
            $normalized = Normalizer::normalize($field, Normalizer::FORM_C);

            if (! is_string($normalized)) {
                throw new InvalidArgumentException('A byte-frame field is not valid Unicode.');
            }

            $bytes .= pack('J', strlen($normalized)).$normalized;
        }

        return $bytes;
    }
}
