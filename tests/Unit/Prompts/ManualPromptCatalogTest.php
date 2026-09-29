<?php

namespace Tests\Unit\Prompts;

use App\AI6\Prompts\ManualFindingListExtractor;
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
            'manual_review' => 'Ticketumsetzung prüfen und Fix-Liste erstellen',
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

    public function test_manual_review_preserves_the_legacy_review_contract(): void
    {
        $entry = $this->app->make(PromptCatalog::class)->entry('manual_review');
        self::assertSame([], $entry->requiredVariables);
        $prompt = $this->app->make(PromptRenderer::class)->render('manual_review', new PromptVariables([]), $this->context());
        foreach ([
            'tickets/<TICKET-ID>.md',
            ManualFindingListExtractor::MARKER_LINE,
            ManualFindingListExtractor::NOTHING_TO_FIX,
            '- Ändere keine Code-Dateien.',
            '- Ändere weder Ticketstatus noch `AGENTS.md`, `CLAUDE.md` oder den normativen Plan.',
        ] as $literal) {
            self::assertStringContainsString($literal, $prompt);
        }
    }

    public function test_readme_maps_each_legacy_prompt_and_keeps_migration_open(): void
    {
        $readme = (string) file_get_contents(base_path('README.md'));
        foreach ([
            '| Review-Prompt aus `ticket-prompt/index.html` | Inhalt übernommen | `manual_review` |',
            '| Statischer Fix-Prompt aus `ticket-prompt/index.html` | Anwendungsfall abgelöst ohne Inhaltsübernahme | `manual_own_review_fix` |',
            '| Fix-Listen-Ablauf des Review-Prompts | Anwendungsfall abgelöst ohne Inhaltsübernahme | `manual_finding_list_fix` |',
            '| `ai/prompts/implementierung_master_prompt.md` | Anwendungsfall abgelöst ohne Inhaltsübernahme | `implementation` |',
            '| `ai/prompts/implementierung_kleines_ticket_prompt.md` | Anwendungsfall abgelöst ohne Inhaltsübernahme | `implementation` |',
            '| `ai/prompts/implementierung_planungs_prompt.md` | Anwendungsfall abgelöst ohne Inhaltsübernahme | `implementation` |',
            'Katalogversion `3`',
            '`QueueReevaluation`',
            '`approval_snapshot_changed`',
            '`review_prompt_binding_mismatch`',
            'AC-01 bis AC-07 und MG-01 bleiben offen',
        ] as $text) {
            self::assertStringContainsString($text, $readme);
        }
    }

    public function test_legacy_migration_gate_template_exists_without_results_or_signature(): void
    {
        $path = base_path('docs/AI6-037_MG-01_ABNAHMEPROTOKOLL.md');
        self::assertFileExists($path);
        $protocol = file_get_contents($path);
        self::assertNotFalse($protocol);

        self::assertMatchesRegularExpression(
            '/^## Befunde und Entscheidung\R+'
            .'- Offene Abweichungen und erforderliche Nacharbeiten:\h*\R'
            .'- Entscheidung zur semantischen Gleichwertigkeit:\h*\R'
            .'- Geprüfter Implementierungscommit und Legacy-Projektstand nochmals bestätigt:\h*\R'
            .'- Name, Datum und Unterschrift:\h*\R{2}'
            .'Eine spätere Änderung/mu',
            $protocol,
        );
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $content = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Prompts/catalog-v3.json');
        self::assertNotFalse($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function context(): RedactionContext
    {
        return new RedactionContext('project-test', null, 'prompt-snapshot');
    }
}
