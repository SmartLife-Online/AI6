<?php

namespace App\AI6\Shared\Redaction;

final class RedactionRuleSet
{
    /** @return list<RedactionRule> */
    public static function defaults(): array
    {
        $secretAssignment = <<<'REGEX'
~(?ix)
(?:
    "(?:secret|password|passwd|api[_-]?key)"
    | '(?:secret|password|passwd|api[_-]?key)'
    | (?:secret|password|passwd|api[_-]?key)
)
\s*[:=]\s*
(?:
    "\K(?:\\.|[^"\\])*(?=")
    | '\K(?:\\.|[^'\\])*(?=')
    | \K(?:\[REDACTED:[A-Z]+\](?:[^\s,;}\]]+)?|[^\s,;}\]]+)
)
~u
REGEX;
        $tokenAssignment = <<<'REGEX'
~(?ix)
(?:
    "(?:access[_-]?token|auth[_-]?token|token)"
    | '(?:access[_-]?token|auth[_-]?token|token)'
    | (?:access[_-]?token|auth[_-]?token|token)
)
\s*[:=]\s*
(?:
    "\K(?:\\.|[^"\\])*(?=")
    | '\K(?:\\.|[^'\\])*(?=')
    | \K(?:\[REDACTED:[A-Z]+\](?:[^\s,;}\]]+)?|[^\s,;}\]]+)
)
~u
REGEX;
        $windowsUserPath = <<<'REGEX'
~(?x)
(?:
    (?<=") [A-Za-z]:[\\/](?:Users|Documents[ ]and[ ]Settings)[\\/][^"\r\n<>|?*]+ (?=")
    | (?<=') [A-Za-z]:[\\/](?:Users|Documents[ ]and[ ]Settings)[\\/][^'\r\n<>|?*]+ (?=')
    | (?<=\() [A-Za-z]:[\\/](?:Users|Documents[ ]and[ ]Settings)[\\/][^)\r\n"'<>|?*]+ (?=\))
    | (?<=\[) [A-Za-z]:[\\/](?:Users|Documents[ ]and[ ]Settings)[\\/][^\]\r\n"'<>|?*]+ (?=\])
    | [A-Za-z]:[\\/](?:Users|Documents[ ]and[ ]Settings)[\\/]
      [^\s"'<>|?*,;)\]}=]+
      (?:[ \t]+(?![A-Za-z]:[\\/]|/(?:home|Users)/)(?=[^\s"'<>|?*,;)\]}=]*[\\/])[^\s"'<>|?*,;)\]}=]+)*
)
~u
REGEX;
        $unixUserPath = <<<'REGEX'
~(?x)
(?:
    (?<=") /(?:home|Users)/[^"\r\n<>]+ (?=")
    | (?<=') /(?:home|Users)/[^'\r\n<>]+ (?=')
    | (?<=\() /(?:home|Users)/[^)\r\n"'<>]+ (?=\))
    | (?<=\[) /(?:home|Users)/[^\]\r\n"'<>]+ (?=\])
    | /(?:home|Users)/
      [^\s"'<>?*,;)\]}=]+
      (?:[ \t]+(?![A-Za-z]:[\\/]|/(?:home|Users)/)(?=[^\s"'<>?*,;)\]}=]*[\\/])[^\s"'<>?*,;)\]}=]+)*
)
~u
REGEX;

        return [
            new RedactionRule(
                'secret-assignment',
                RedactionMatchType::SECRET,
                $secretAssignment,
                10,
            ),
            new RedactionRule(
                'token-assignment',
                RedactionMatchType::TOKEN,
                $tokenAssignment,
                15,
            ),
            new RedactionRule(
                'bearer-token',
                RedactionMatchType::TOKEN,
                '~(?i)\bBearer\s+\K(?:\[REDACTED:TOKEN\](?:[A-Za-z0-9._+/=-]+)?|[A-Za-z0-9._+/=-]{8,})~u',
                20,
            ),
            new RedactionRule(
                'provider-token',
                RedactionMatchType::TOKEN,
                '~(?<![A-Za-z0-9])(?:sk-[A-Za-z0-9_-]{8,}|gh[opusr]_[A-Za-z0-9]{8,})(?![A-Za-z0-9])~u',
                30,
            ),
            new RedactionRule(
                'uri-credential',
                RedactionMatchType::CREDENTIAL,
                '~(?<=://)(?:\[REDACTED:CREDENTIAL\](?:[^/\s@]+)?|[^/\s:@]+:[^/\s@]+)(?=@)~u',
                40,
            ),
            new RedactionRule(
                'windows-user-path',
                RedactionMatchType::SENSITIVE_PATH,
                $windowsUserPath,
                50,
            ),
            new RedactionRule(
                'unix-user-path',
                RedactionMatchType::SENSITIVE_PATH,
                $unixUserPath,
                60,
            ),
        ];
    }
}
