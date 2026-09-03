<?php

namespace Tests\Unit\Shared\Markdown;

use App\AI6\Shared\Markdown\ControlSequenceSanitizer;
use App\AI6\Shared\Markdown\SafeTextRenderer;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use RuntimeException;
use Tests\TestCase;

/**
 * TC-06 of AI6-031: HTML, script, ANSI CSI, OSC and other control sequences
 * are shown as visible text; nothing is interpreted and stored content stays
 * untouched.
 */
final class ControlSequenceSanitizerTest extends TestCase
{
    public function test_csi_osc_and_other_escape_sequences_are_neutralized_as_one_visible_placeholder_each(): void
    {
        $sanitizer = new ControlSequenceSanitizer;
        $placeholder = ControlSequenceSanitizer::PLACEHOLDER;

        self::assertSame("{$placeholder}rot{$placeholder}", $sanitizer->sanitize("\x1b[31mrot\x1b[0m"));
        self::assertSame("{$placeholder}Link{$placeholder}", $sanitizer->sanitize("\x1b]8;;http://evil.test\x1b\\Link\x1b]8;;\x1b\\"));
        self::assertSame("{$placeholder}Titel", $sanitizer->sanitize("\x1b]0;Fenster\x07Titel"));
        // ESC followed by a byte in 0x30–0x7E is an Fp/Fs escape (ECMA-48), so "ESC d" is one sequence; a trailing lone ESC is one placeholder.
        self::assertSame("a{$placeholder}b{$placeholder}c{$placeholder}", $sanitizer->sanitize("a\x1b(Bb\x1bZc\x1bd"));
        self::assertSame("x{$placeholder}", $sanitizer->sanitize("x\x1b"));
        self::assertSame("{$placeholder}offen ohne Ende", $sanitizer->sanitize("\x1b]offen ohne Ende"));
        self::assertSame("{$placeholder}{$placeholder}{$placeholder}bewegt{$placeholder}", $sanitizer->sanitize("\x1b[2J\x1b[H\x1b[?25lbewegt\x1b[1;1H"));
    }

    public function test_control_and_format_characters_are_neutralized_except_tab_and_line_feed(): void
    {
        $sanitizer = new ControlSequenceSanitizer;
        $placeholder = ControlSequenceSanitizer::PLACEHOLDER;

        $sanitized = $sanitizer->sanitize("Tab\tZeile\nNull\x00Bell\x07Del\x7fC1\u{0085}Bidi\u{202E}evil\u{2066}Zero\u{200B}Ende\r\nCR\rX");

        self::assertSame(
            "Tab\tZeile\nNull{$placeholder}Bell{$placeholder}Del{$placeholder}C1{$placeholder}Bidi{$placeholder}evil{$placeholder}Zero{$placeholder}Ende\nCR\nX",
            $sanitized,
        );
        self::assertSame(0, preg_match('/(?![\t\n])[\p{Cc}\p{Cf}]/u', $sanitized), 'No control or format character survives the presentation step.');
    }

    public function test_html_and_script_fixtures_stay_visible_text_and_are_never_interpreted(): void
    {
        $sanitizer = new ControlSequenceSanitizer;
        $fixture = '<script>alert(1)</script><img src=x onerror="alert(2)"><a href="javascript:alert(3)">x</a>';

        $sanitized = $sanitizer->sanitize($fixture);

        // The sanitizer changes no text byte; Blade's output escaping renders
        // the tags as visible characters instead of elements.
        self::assertSame($fixture, $sanitized);
        $escaped = e($sanitized);
        self::assertStringNotContainsString('<script', $escaped);
        self::assertStringNotContainsString('<img', $escaped);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $escaped);
        self::assertStringNotContainsString('<', $escaped, 'No element survives the output escaping.');
    }

    public function test_sanitizing_is_pure_idempotent_and_leaves_the_contract_content_untouched(): void
    {
        $sanitizer = new ControlSequenceSanitizer;
        $stored = "Vertrag \x1b[1mfett\x1b[0m bleibt";
        $copy = $stored;

        $first = $sanitizer->sanitize($stored);
        $second = $sanitizer->sanitize($first);

        self::assertSame($copy, $stored, 'The stored input is never mutated.');
        self::assertSame($first, $second, 'A second presentation pass changes nothing.');
        self::assertStringNotContainsString("\x1b", $first);
        self::assertStringContainsString('fett', $first);
    }

    public function test_split_values_stay_split_so_neutralization_never_joins_text(): void
    {
        $sanitizer = new ControlSequenceSanitizer;
        $placeholder = ControlSequenceSanitizer::PLACEHOLDER;

        self::assertSame("ab{$placeholder}cd", $sanitizer->sanitize("ab\x1b[0mcd"));
        self::assertStringNotContainsString('abcd', $sanitizer->sanitize("ab\x1b[0mcd"));
    }

    public function test_invalid_utf8_is_refused_with_a_typed_exception(): void
    {
        $this->expectException(RuntimeException::class);

        (new ControlSequenceSanitizer)->sanitize("valid \xff invalid");
    }

    public function test_the_renderer_redacts_raw_text_before_the_presentation_step(): void
    {
        $renderer = $this->app->make(SafeTextRenderer::class);
        $context = new RedactionContext('project-1', 'run-1', 'run-timeline');

        $rendered = $renderer->render("password=hunter2 \x1b[31m<b>x</b>\x1b[0m", $context);

        self::assertStringContainsString('password='.RedactionMatchType::SECRET->marker(), $rendered);
        self::assertStringNotContainsString('hunter2', $rendered);
        self::assertStringNotContainsString("\x1b", $rendered);
        self::assertStringContainsString('<b>x</b>', $rendered, 'Markup stays text for Blade to escape.');
    }
}
