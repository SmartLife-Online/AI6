<?php

namespace Tests\Unit\Shared\Markdown;

use App\AI6\Shared\Markdown\AllowedHtmlPolicy;
use App\AI6\Shared\Markdown\SafeMarkdownRenderer;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\TestCase;

final class SafeMarkdownRendererTest extends TestCase
{
    public function test_renderer_redacts_before_rendering_and_removes_markdown_xss(): void
    {
        $markdown = <<<'MARKDOWN'
password=hunter2

<script>alert('raw')</script>
<a href="https://example.test" onclick="alert('event')">raw link</a>

[script](javascript:alert(1)) [data](data:text/html;base64,PHNjcmlwdD4=) [safe](https://example.test/path)

![tracking](data:image/svg+xml;base64,PHN2Zz4=)
MARKDOWN;

        $html = $this->app->make(SafeMarkdownRenderer::class)->render(
            $markdown,
            new RedactionContext('project-1', 'run-1', 'markdown'),
        );

        self::assertStringContainsString('password=[REDACTED:SECRET]', $html);
        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<a href="https://example.test" onclick=', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        self::assertStringNotContainsString('javascript:', strtolower($html));
        self::assertStringNotContainsString('data:', strtolower($html));
        self::assertStringContainsString('<a href="https://example.test/path">safe</a>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function test_html_policy_keeps_only_allowlisted_elements_attributes_and_link_schemes(): void
    {
        $source = '<p class="foreign">Text <strong onclick="x">stark</strong></p>'
            .'<a href="java&#x73;cript:alert(1)" onclick="x">schlecht</a>'
            .'<a href="mailto:user@example.test" title="Kontakt">gut</a>'
            .'<iframe src="https://example.test"></iframe>';
        $html = (new AllowedHtmlPolicy)->sanitize($source);

        self::assertSame(
            '<p>Text <strong>stark</strong></p><a>schlecht</a>'
            .'<a href="mailto:user@example.test" title="Kontakt">gut</a>',
            $html,
        );
    }

    public function test_empty_attribute_values_are_safe_and_do_not_create_active_links(): void
    {
        $html = (new AllowedHtmlPolicy)->sanitize(
            '<a href="">empty link</a><a title=\'\'>empty title</a>',
        );

        self::assertSame('<a>empty link</a><a title="">empty title</a>', $html);

        $rendered = $this->app->make(SafeMarkdownRenderer::class)->render(
            '[empty link]()',
            new RedactionContext('project-1', 'run-1', 'markdown'),
        );

        self::assertStringContainsString('<a>empty link</a>', $rendered);
        self::assertStringNotContainsString('href=""', $rendered);
    }
}
