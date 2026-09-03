<?php

namespace App\AI6\Shared\Markdown;

use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;

/**
 * The fixed order for plain-text presentation of untrusted content: the
 * central redaction exactly once, then the presentation sanitization exactly
 * once (SEC-007).
 *
 * Text that already crossed the central redaction at its persistence boundary
 * is presented without a second redaction, because a repeated pass can consume
 * evidence next to an existing marker; it still receives the one presentation
 * step. This class owns no pattern of its own.
 */
final readonly class SafeTextRenderer
{
    public function __construct(
        private Redactor $redactor,
        private ControlSequenceSanitizer $sanitizer,
    ) {}

    /** Raw untrusted text: redact once, then sanitize once. */
    public function render(string $text, RedactionContext $context): string
    {
        $redacted = $this->redactor->redact($text, $context);

        return $this->sanitizer->sanitize($redacted->text);
    }

    /** Text persisted behind the central redaction: sanitize once, never redact again. */
    public function present(string $redactedText): string
    {
        return $this->sanitizer->sanitize($redactedText);
    }
}
