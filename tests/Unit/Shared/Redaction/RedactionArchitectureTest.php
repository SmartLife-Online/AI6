<?php

namespace Tests\Unit\Shared\Redaction;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RedactionArchitectureTest extends TestCase
{
    private const DEFINITION_ALLOWLIST = [
        '/app/AI6/Shared/Redaction/',
        '/tests/Unit/Shared/Redaction/RedactionArchitectureTest.php',
        '/tests/Unit/Shared/Redaction/RedactorTest.php',
    ];

    public function test_no_repository_source_outside_redaction_defines_secret_patterns_or_markers(): void
    {
        $root = dirname(__DIR__, 4);
        $violations = [];

        foreach (['app', 'bootstrap', 'config', 'resources', 'routes', 'scripts', 'tests'] as $directory) {
            $path = $root.'/'.$directory;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'jsx', 'ts', 'tsx'], true)) {
                    continue;
                }

                $filePath = str_replace('\\', '/', $file->getPathname());
                if ($this->isDefinitionAllowed($filePath)) {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                self::assertNotFalse($source);

                if ($this->definesRedactionRules($source)) {
                    $violations[] = substr($filePath, strlen(str_replace('\\', '/', $root)) + 1);
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function test_rule_detector_covers_markers_constructors_and_regex_collections(): void
    {
        foreach ([
            "return '[REDACTED:SECRET]';",
            'new RedactionRule(\'duplicate\', $type, \'~secret=.+~\', 1);',
            'preg_replace(\'/password=.*/\', \'[hidden]\', $value);',
            '$patterns = [\'~Bearer\\s+.+~\'];',
            '$patterns = [\'~sk-[A-Za-z0-9]+~\'];',
        ] as $source) {
            self::assertTrue($this->definesRedactionRules($source), $source);
        }

        self::assertFalse($this->definesRedactionRules('return app(Redactor::class)->redact($value, $context);'));
    }

    private function definesRedactionRules(string $source): bool
    {
        return preg_match('/(?:\breturn\s+|=>\s*|=\s*(?:\[\s*)?)[\'\"][^\'\"\r\n]*\[REDACTED:/i', $source) === 1
            || preg_match('/new\s+RedactionRule\s*\(/', $source) === 1
            || preg_match(
                '/(?:preg_(?:match(?:_all)?|replace)|str_replace)\s*\([^;]{0,800}(?:secret|password|passwd|bearer|token|credential|sensitive[_-]?path|sk-|gh[opusr]_)/is',
                $source,
            ) === 1
            || preg_match(
                '/[\'\"][~#\/][^\r\n]{0,300}(?:secret|password|passwd|bearer|token|credential|sensitive[_-]?path|sk-|gh[opusr]_)/i',
                $source,
            ) === 1;
    }

    private function isDefinitionAllowed(string $path): bool
    {
        foreach (self::DEFINITION_ALLOWLIST as $allowed) {
            if (str_contains($path, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
