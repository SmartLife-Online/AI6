<?php

namespace Tests\Unit\Prompts;

use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Shared\Redaction\RedactionContext;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

final class ManualPromptCatalogTest extends TestCase
{
    public function test_manual_entries_match_the_golden_fixture_and_use_only_the_central_renderer(): void
    {
        $fixture = $this->fixture();
        $catalog = $this->app->make(PromptCatalog::class);
        $renderer = $this->app->make(PromptRenderer::class);
        $expected = [
            'manual_own_review_fix' => 'Eigenen Reviewbefund beheben und re-reviewen',
            'manual_foreign_fix_review' => 'Fremde Fixes read-only prüfen und re-reviewen',
            'manual_finding_list_fix' => 'Findings aus einer Reviewantwort prüfen und beheben',
        ];

        foreach ($expected as $id => $displayName) {
            $entry = $catalog->entry($id);
            self::assertSame('1', $entry->version);
            self::assertSame($displayName, $entry->displayName);
            self::assertArrayHasKey($id, $fixture['entries']);

            $variables = is_array($fixture['entries'][$id]['variables'] ?? null)
                ? $fixture['entries'][$id]['variables']
                : [];
            $snapshot = $renderer->snapshot([
                new PromptRenderRequest($id, new PromptVariables($variables)),
            ], $this->context());

            self::assertSame($fixture['entries'][$id]['prompt'], $snapshot->renderedPrompts[$id]);
            self::assertSame($fixture['entries'][$id]['hash'], $snapshot->hash);
            self::assertSame($fixture['catalog_version'], $snapshot->catalogVersion);
        }

        $renderParameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionMethod(PromptRenderer::class, 'render'))->getParameters(),
        );
        self::assertSame([], array_filter($renderParameters, static fn (string $name): bool => preg_match('/provider|adapter|claude|codex/i', $name) === 1));

        $catalogFiles = glob(dirname(__DIR__, 3).'/app/AI6/Prompts/*.php');
        self::assertIsArray($catalogFiles);
        self::assertCount(1, array_filter($catalogFiles, static fn (string $path): bool => basename($path) === 'PromptCatalog.php'));
        self::assertCount(1, array_filter($catalogFiles, static fn (string $path): bool => basename($path) === 'PromptRenderer.php'));
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $content = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Prompts/catalog-v2.json');
        self::assertNotFalse($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function context(): RedactionContext
    {
        return new RedactionContext('project-test', null, 'prompt-snapshot');
    }
}
