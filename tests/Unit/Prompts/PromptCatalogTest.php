<?php

namespace Tests\Unit\Prompts;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Git\CanonicalJson;
use App\AI6\Prompts\PromptCatalog;
use App\AI6\Prompts\PromptEntry;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderingError;
use App\AI6\Prompts\PromptRenderingException;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Prompts\ReviewPromptProfile;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Error;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use SplFileInfo;
use Tests\TestCase;

final class PromptCatalogTest extends TestCase
{
    public function test_role_prompt_snapshot_and_result_schema_are_provider_independent(): void
    {
        $renderer = $this->app->make(PromptRenderer::class);
        $request = new PromptRenderRequest('quality_review', new PromptVariables(['context' => 'gebunden']));
        $rendererParameters = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(PromptRenderer::class, 'snapshot'))->getParameters());
        $requestParameters = array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(PromptRenderRequest::class, '__construct'))->getParameters());
        self::assertSame(['requests', 'context'], $rendererParameters);
        self::assertSame(['entryId', 'variables', 'reviewProfileId'], $requestParameters);
        self::assertSame([], array_filter([...$rendererParameters, ...$requestParameters], static fn (string $name): bool => preg_match('/provider|model/i', $name) === 1));
        $first = $renderer->snapshot([$request], $this->context());
        $second = $renderer->snapshot([$request], $this->context());

        self::assertSame($first->renderedPrompts, $second->renderedPrompts);
        self::assertSame($first->hash, $second->hash);
        self::assertSame('1', $first->catalogVersion);
    }

    public function test_prompt_source_has_exactly_one_catalog_and_renderer(): void
    {
        $catalogs = [];
        $renderers = [];
        $templatesOutsidePrompts = [];

        foreach ($this->modulePhpFiles() as $path => $contents) {
            if (preg_match('/\bclass\s+\w*PromptCatalog\w*\b/', $contents) === 1) {
                $catalogs[] = $path;
            }
            if (preg_match('/\bclass\s+\w*PromptRenderer\w*\b/', $contents) === 1) {
                $renderers[] = $path;
            }
            // Any `{{name}}` placeholder is prompt template text; PromptRenderer builds them as '{{'.$key.'}}'.
            if (! str_contains($path, '/app/AI6/Prompts/') && preg_match('/\{\{\s*\w+\s*\}\}/', $contents) === 1) {
                $templatesOutsidePrompts[] = $path;
            }
        }

        self::assertCount(1, $catalogs);
        self::assertCount(1, $renderers);
        self::assertStringContainsString('/app/AI6/Prompts/PromptCatalog.php', $catalogs[0]);
        self::assertStringContainsString('/app/AI6/Prompts/PromptRenderer.php', $renderers[0]);
        self::assertSame([], $templatesOutsidePrompts);
    }

    /**
     * Every PHP file of the module tree with forward-slash paths, traversed exactly once.
     *
     * @return array<string, string>
     */
    private function modulePhpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/app/AI6'));
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files[str_replace('\\', '/', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }

        self::assertNotSame([], $files);

        return $files;
    }

    public function test_six_purposes_render_byte_identically_against_the_golden_fixture(): void
    {
        $fixture = $this->fixture();
        $catalog = $this->app->make(PromptCatalog::class);
        $renderer = $this->app->make(PromptRenderer::class);

        self::assertSame($fixture['catalog_version'], $catalog->version);
        self::assertSame(
            ['finding_verification', 'fix', 'human_response', 'implementation', 'quality_review', 'security_review'],
            array_column($catalog->entries(), 'id'),
        );
        foreach ($fixture['entries'] as $id => $expected) {
            $request = new PromptRenderRequest($id, new PromptVariables(['context' => $fixture['context']]));
            $first = $renderer->snapshot([$request], $this->context());
            $second = $renderer->snapshot([$request], $this->context());
            self::assertSame($expected['prompt'], $first->renderedPrompts[$id]);
            self::assertSame($expected['hash'], $first->hash);
            self::assertSame($first->renderedPrompts, $second->renderedPrompts);
            self::assertSame($first->hash, $second->hash);
            self::assertSame('1', $first->catalogVersion);
        }
    }

    public function test_catalog_and_review_profile_versions_invalidate_only_authorized_snapshots(): void
    {
        $base = PromptCatalog::defaults();
        $request = new PromptRenderRequest(
            'quality_review',
            new PromptVariables(['context' => 'gleich']),
            'architecture',
        );
        $first = $this->renderer($base)->snapshot([$request], $this->context());
        $entryChanged = $base->withEntry(
            new PromptEntry('quality_review', '2', $base->entry('quality_review')->template."\nVersionierter Zusatz.", ['context']),
            '2',
        );
        $profileChanged = $base->withReviewProfile(
            new ReviewPromptProfile('architecture', '2', 'Architektur', 'Geänderter autorisierter Fokus.'),
            '2',
        );

        self::assertNotSame($first->hash, $this->renderer($entryChanged)->snapshot([$request], $this->context())->hash);
        self::assertNotSame($first->hash, $this->renderer($profileChanged)->snapshot([$request], $this->context())->hash);
        self::assertSame($first->hash, $this->renderer($base)->snapshot([$request], $this->context())->hash);
    }

    public function test_review_profiles_are_provider_independent_and_extra_entries_use_the_same_catalog_and_renderer(): void
    {
        $catalog = PromptCatalog::defaults();
        self::assertSame(9, count($catalog->reviewProfiles()));
        self::assertSame(
            ['entryId', 'variables', 'context', 'reviewProfileId'],
            array_map(
                static fn (ReflectionParameter $parameter): string => $parameter->getName(),
                (new ReflectionMethod(PromptRenderer::class, 'render'))->getParameters(),
            ),
        );
        self::assertSame(
            ['requests', 'context'],
            array_map(
                static fn (ReflectionParameter $parameter): string => $parameter->getName(),
                (new ReflectionMethod(PromptRenderer::class, 'snapshot'))->getParameters(),
            ),
        );
        foreach ([PromptCatalog::class, PromptRenderer::class, PromptEntry::class, ReviewPromptProfile::class] as $class) {
            $reflection = new ReflectionClass($class);
            $dimensionNames = array_map(
                static fn (ReflectionProperty $property): string => $property->getName(),
                $reflection->getProperties(),
            );
            $dimensionNames = [
                ...$dimensionNames,
                ...array_map(
                    static fn (ReflectionMethod $method): string => $method->getName(),
                    $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                ),
            ];
            $constructor = $reflection->getConstructor();
            if ($constructor !== null) {
                $dimensionNames = [
                    ...$dimensionNames,
                    ...array_map(
                        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
                        $constructor->getParameters(),
                    ),
                ];
            }
            foreach ($dimensionNames as $name) {
                self::assertDoesNotMatchRegularExpression('/provider|adapter/i', $name);
            }
        }

        $extended = $catalog->withEntry(new PromptEntry('test_extension', '1', 'Test: {{context}}', ['context']), '2');
        self::assertSame(
            'Test: erweitert',
            $this->renderer($extended)->render(
                'test_extension',
                new PromptVariables(['context' => 'erweitert']),
                $this->context(),
            ),
        );

        $promptFiles = glob(dirname(__DIR__, 3).'/app/AI6/Prompts/*.php');
        self::assertIsArray($promptFiles);
        self::assertCount(1, array_filter($promptFiles, static fn (string $path): bool => basename($path) === 'PromptCatalog.php'));
        self::assertCount(1, array_filter($promptFiles, static fn (string $path): bool => basename($path) === 'PromptRenderer.php'));
    }

    public function test_prompt_input_limit_and_utf8_boundary_fail_without_a_snapshot(): void
    {
        $catalog = PromptCatalog::defaults();
        $request = new PromptRenderRequest('implementation', new PromptVariables(['context' => 'abc']));
        $rendered = $this->renderer($catalog)->render('implementation', $request->variables, $this->context());
        $maximum = strlen($rendered);
        $accepted = $this->renderer($catalog, new AgentInputLimits(1, 1, 1, 1, $maximum))
            ->snapshot([$request], $this->context());
        self::assertNotSame('', $accepted->hash);

        try {
            $this->renderer($catalog, new AgentInputLimits(1, 1, 1, 1, $maximum - 1))
                ->snapshot([$request], $this->context());
            self::fail('The over-limit final prompt unexpectedly produced a snapshot.');
        } catch (PromptRenderingException $exception) {
            self::assertSame(PromptRenderingError::INPUT_BYTES_EXCEEDED, $exception->reason);
            self::assertStringNotContainsString('abc', $exception->getMessage());
        }

        $invalidUtf8 = "\xC3\x28";
        try {
            $this->renderer($catalog, new AgentInputLimits(1, 1, 1, 1, 1))->snapshot([
                new PromptRenderRequest('implementation', new PromptVariables(['context' => $invalidUtf8])),
            ], $this->context());
            self::fail('The oversized raw prompt input unexpectedly reached redaction.');
        } catch (PromptRenderingException $exception) {
            self::assertSame(PromptRenderingError::INPUT_BYTES_EXCEEDED, $exception->reason);
            self::assertStringNotContainsString($invalidUtf8, $exception->getMessage());
        }

        try {
            $this->renderer($catalog)->snapshot([
                new PromptRenderRequest('implementation', new PromptVariables(['context' => $invalidUtf8])),
            ], $this->context());
            self::fail('The invalid prompt input unexpectedly produced a snapshot.');
        } catch (PromptRenderingException $exception) {
            self::assertSame(PromptRenderingError::UTF8_INVALID, $exception->reason);
            self::assertStringNotContainsString($invalidUtf8, $exception->getMessage());
        }
    }

    public function test_prompt_snapshot_is_immutable(): void
    {
        $snapshot = $this->app->make(PromptRenderer::class)->snapshot([
            new PromptRenderRequest('implementation', new PromptVariables(['context' => 'unveränderlich'])),
        ], $this->context());

        $this->expectException(Error::class);
        (new ReflectionProperty($snapshot, 'renderedPrompts'))->setValue($snapshot, ['implementation' => 'manipuliert']);
    }

    public function test_catalog_rejects_incomplete_variable_contracts_and_empty_snapshots(): void
    {
        $invalidEntries = [
            new PromptEntry('invalid', '1', '{{context}} {{extra}}', ['context']),
            new PromptEntry('invalid', '1', '{{context}} {{context}}', ['context']),
            new PromptEntry('invalid', '1', '{{invalid-name}}', ['invalid-name']),
        ];
        $rejected = 0;
        foreach ($invalidEntries as $entry) {
            try {
                new PromptCatalog('1', [$entry], [new ReviewPromptProfile('tests', '1', 'Tests', 'Fokus')]);
                self::fail('The invalid prompt variable contract was accepted.');
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(count($invalidEntries), $rejected);

        try {
            $this->app->make(PromptRenderer::class)->snapshot([], $this->context());
            self::fail('An empty prompt snapshot was accepted.');
        } catch (PromptRenderingException $exception) {
            self::assertSame(PromptRenderingError::VARIABLES_INVALID, $exception->reason);
        }
    }

    public function test_catalog_changes_require_a_strictly_increased_catalog_version(): void
    {
        $catalog = PromptCatalog::defaults();
        $operations = [
            static fn () => $catalog->withEntry(new PromptEntry('implementation', '2', 'Neu: {{context}}', ['context']), '1'),
            static fn () => $catalog->withEntry(new PromptEntry('implementation', '2', 'Neu: {{context}}', ['context']), '0'),
            static fn () => $catalog->withReviewProfile(new ReviewPromptProfile('tests', '2', 'Tests', 'Neuer Fokus'), '1'),
            static fn () => $catalog->withReviewProfile(new ReviewPromptProfile('tests', '2', 'Tests', 'Neuer Fokus'), '0'),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('A catalog change without an increased version was accepted.');
            } catch (PromptRenderingException $exception) {
                self::assertSame(PromptRenderingError::CATALOG_VERSION_NOT_INCREASED, $exception->reason);
                self::assertSame('Prompt rendering failed: catalog_version_not_increased.', $exception->getMessage());
            }
        }
    }

    private function renderer(PromptCatalog $catalog, ?AgentInputLimits $limits = null): PromptRenderer
    {
        return new PromptRenderer(
            $catalog,
            $this->app->make(Redactor::class),
            $limits ?? $this->app->make(AgentInputLimits::class),
            $this->app->make(CanonicalJson::class),
        );
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $content = file_get_contents(dirname(__DIR__, 2).'/Fixtures/Prompts/catalog-v1.json');
        self::assertNotFalse($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    private function context(): RedactionContext
    {
        return new RedactionContext('project-test', null, 'prompt-snapshot');
    }
}
