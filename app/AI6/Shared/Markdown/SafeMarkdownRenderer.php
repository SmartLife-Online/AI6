<?php

namespace App\AI6\Shared\Markdown;

use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use League\CommonMark\MarkdownConverter;

final readonly class SafeMarkdownRenderer
{
    public function __construct(
        private Redactor $redactor,
        private MarkdownConverter $converter,
        private AllowedHtmlPolicy $htmlPolicy,
    ) {}

    public function render(string $markdown, RedactionContext $redactionContext): string
    {
        $redacted = $this->redactor->redact($markdown, $redactionContext);
        $rendered = $this->converter->convert($redacted->text);

        return $this->htmlPolicy->sanitize((string) $rendered);
    }
}
