<?php

namespace App\AI6\Shared\Markdown;

use RuntimeException;

/**
 * The presentation boundary for terminal control sequences and control
 * characters (SEC-007).
 *
 * It neutralizes ANSI CSI and OSC sequences, every other escape sequence and
 * every remaining control or format character by replacing each of them with
 * exactly one visible replacement character. Nothing is interpreted, and the
 * neutralized pieces are never joined: text that a control sequence separated
 * stays separated, so a value the central redaction did not see as one token
 * cannot become one on screen. Tabs and line feeds stay; carriage returns are
 * folded into line feeds.
 *
 * This class is a presentation step only. It defines no secret, token,
 * credential or path pattern and never changes stored content; the central
 * redaction in app/AI6/Shared/Redaction/ runs before it, exactly once.
 */
final class ControlSequenceSanitizer
{
    public const PLACEHOLDER = "\u{FFFD}";

    /**
     * Terminated OSC first (its payload is arbitrary text up to BEL or ST), then
     * CSI with parameter, intermediate and final bytes, then the other Fe/Fp/Fs
     * escape forms, then every leftover ESC and finally every remaining control
     * (Cc) or format (Cf) character except tab and line feed.
     */
    private const SEQUENCES = '/\x1B\][^\x07\x1B\n]*(?:\x07|\x1B\\\\)'
        .'|\x1B\[[\x30-\x3F]*[\x20-\x2F]*[\x40-\x7E]'
        .'|\x1B[\x20-\x2F]*[\x30-\x7E]'
        .'|\x1B'
        .'|(?![\t\n])[\p{Cc}\p{Cf}]/u';

    public function sanitize(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new RuntimeException('The control-sequence sanitizer requires valid UTF-8 input.');
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $sanitized = preg_replace(self::SEQUENCES, self::PLACEHOLDER, $normalized);
        if ($sanitized === null) {
            throw new RuntimeException('The control-sequence sanitizer could not be evaluated.');
        }

        return $sanitized;
    }
}
