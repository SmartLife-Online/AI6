<?php

namespace Tests\Unit\Shared\Redaction;

use App\AI6\Shared\Markdown\ControlSequenceSanitizer;
use App\AI6\Shared\Markdown\SafeTextRenderer;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\Redactor;
use Tests\TestCase;

/**
 * TC-07 of AI6-031: the golden integration of the central redaction and the
 * presentation sanitization. A secret is redacted exactly once, the
 * presentation step runs exactly once afterwards, the reversed order is
 * falsified by a leaking token, and the UI path owns no second pattern list.
 */
final class PresentationOrderGoldenTest extends TestCase
{
    public function test_the_golden_vector_is_reproduced_with_exactly_one_marker_per_secret(): void
    {
        $fixture = $this->fixture();
        $renderer = $this->app->make(SafeTextRenderer::class);
        $context = new RedactionContext($fixture['project_id'], $fixture['run_id'], $fixture['context_identifier']);

        $rendered = $renderer->render($fixture['input'], $context);

        self::assertSame($fixture['expected_output'], $rendered);
        foreach ($fixture['secrets'] as $secret) {
            self::assertStringNotContainsString($secret, $rendered, 'No raw secret reaches the presentation.');
        }
        foreach ($fixture['marker_counts'] as $type => $count) {
            self::assertSame($count, substr_count($rendered, RedactionMatchType::from($type)->marker()), $type);
        }
        self::assertSame(0, preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $rendered), 'No control character survives.');
        self::assertSame($fixture['placeholder_count'], substr_count($rendered, ControlSequenceSanitizer::PLACEHOLDER));
    }

    public function test_the_reversed_order_is_falsified_because_sanitizing_first_hides_a_token_from_the_redaction(): void
    {
        $fixture = $this->fixture();
        $context = new RedactionContext($fixture['project_id'], $fixture['run_id'], $fixture['context_identifier']);
        $redactor = $this->app->make(Redactor::class);
        $sanitizer = new ControlSequenceSanitizer;

        // Presentation first, redaction second: the vertical tab between
        // "Bearer" and its token is whitespace for the rule but a control
        // character for the sanitizer; neutralized first, the token is no
        // longer a bearer token to the redaction and leaks.
        $reversed = $redactor->redact($sanitizer->sanitize($fixture['input']), $context)->text;
        self::assertSame($fixture['reversed_order_output'], $reversed);
        self::assertNotSame($fixture['expected_output'], $reversed, 'The fixture discriminates the order.');
        foreach ($fixture['reversed_order_leaks'] as $leak) {
            self::assertStringContainsString($leak, $reversed, 'The reversed order leaks the token.');
            self::assertStringNotContainsString($leak, $fixture['expected_output'], 'The fixed order redacts it.');
        }

        // The renderer produces the fixed order and nothing else.
        $rendered = $this->app->make(SafeTextRenderer::class)->render($fixture['input'], $context);
        self::assertSame($fixture['expected_output'], $rendered);
        self::assertNotSame($reversed, $rendered);
    }

    public function test_redaction_runs_exactly_once_and_before_the_single_presentation_step(): void
    {
        $fixture = $this->fixture();
        $context = new RedactionContext($fixture['project_id'], $fixture['run_id'], $fixture['context_identifier']);
        $redactor = $this->app->make(Redactor::class);
        $sanitizer = new ControlSequenceSanitizer;

        // The central redaction alone yields exactly the golden match list;
        // the renderer adds no match and removes none.
        $redacted = $redactor->redact($fixture['input'], $context);
        self::assertCount(array_sum($fixture['marker_counts']), $redacted->matches);
        self::assertSame($fixture['expected_output'], $sanitizer->sanitize($redacted->text));

        $renderer = $this->app->make(SafeTextRenderer::class);
        self::assertSame($sanitizer->sanitize($redacted->text), $renderer->render($fixture['input'], $context));

        // Persisted-redacted text takes the presentation step only: a second
        // redaction pass is never applied to it.
        self::assertSame($sanitizer->sanitize($redacted->text), $renderer->present($redacted->text));

        // Structural half: exactly one redaction call in the renderer, ahead
        // of the sanitization inside the same render() body.
        $source = file_get_contents(app_path('AI6/Shared/Markdown/SafeTextRenderer.php'));
        self::assertIsString($source);
        self::assertSame(1, substr_count($source, '->redact('), 'Exactly one redaction call.');
        self::assertSame(2, substr_count($source, '->sanitize('), 'Exactly one sanitization per path: render and present.');
        $renderBody = substr($source, (int) strpos($source, 'public function render('), (int) strpos($source, 'public function present(') - (int) strpos($source, 'public function render('));
        self::assertSame(1, substr_count($renderBody, '->redact('));
        self::assertSame(1, substr_count($renderBody, '->sanitize('));
        self::assertLessThan((int) strpos($renderBody, '->sanitize('), (int) strpos($renderBody, '->redact('), 'The redaction precedes the presentation step in render().');
        $presentBody = substr($source, (int) strpos($source, 'public function present('));
        self::assertSame(0, substr_count($presentBody, '->redact('), 'present() never redacts again.');
    }

    public function test_the_ui_path_defines_no_second_pattern_list(): void
    {
        foreach ([
            app_path('AI6/Shared/Markdown/SafeTextRenderer.php'),
            app_path('AI6/Shared/Markdown/ControlSequenceSanitizer.php'),
            app_path('AI6/Runs/RunTimelinePage.php'),
            app_path('AI6/Runs/RunArtifactDownloadController.php'),
            resource_path('views/runs/timeline.blade.php'),
            resource_path('views/runs/partials/retention.blade.php'),
            resource_path('views/runs/partials/truncated.blade.php'),
        ] as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source, $path);
            foreach (['RedactionRule', 'RedactionRuleSet', 'RedactionPolicy', 'RedactionMatchType', '[REDACTED:', 'preg_replace_callback'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $path.' must not contain '.$forbidden);
            }
        }
        $page = file_get_contents(app_path('AI6/Runs/RunTimelinePage.php'));
        self::assertIsString($page);
        self::assertStringNotContainsString('->redact(', $page, 'The page never redacts on its own; it consumes the renderer.');
        self::assertStringNotContainsString('Redactor', $page);
        self::assertStringContainsString('SafeTextRenderer', $page);
    }

    /**
     * @return array{project_id: string, run_id: string, context_identifier: string, input: string, expected_output: string, reversed_order_output: string, secrets: list<string>, reversed_order_leaks: list<string>, marker_counts: array<string, int>, placeholder_count: int}
     */
    private function fixture(): array
    {
        $contents = file_get_contents(__DIR__.'/Fixtures/presentation-golden.json');
        self::assertNotFalse($contents);
        $fixture = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);

        return $fixture;
    }
}
