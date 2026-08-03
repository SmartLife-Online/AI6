<?php

namespace App\AI6\Shared\Redaction;

enum RedactionMatchType: string
{
    case SECRET = 'secret';
    case TOKEN = 'token';
    case CREDENTIAL = 'credential';
    case SENSITIVE_PATH = 'sensitive_path';

    public function marker(): string
    {
        return match ($this) {
            self::SECRET => '[REDACTED:SECRET]',
            self::TOKEN => '[REDACTED:TOKEN]',
            self::CREDENTIAL => '[REDACTED:CREDENTIAL]',
            self::SENSITIVE_PATH => '[REDACTED:PATH]',
        };
    }
}
