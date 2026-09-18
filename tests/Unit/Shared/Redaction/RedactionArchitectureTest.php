<?php

namespace Tests\Unit\Shared\Redaction;

use PhpParser\Error;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
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
            'str_replace(\'secret=\', \'[hidden]\', $value);',
            'str_replace([\'password=\', \'token=\'], \'[hidden]\', $value);',
            'str_replace(subject: $value, search: \'password=\', replace: \'[hidden]\');',
            'preg_match(\'/token=[^;]+/i\', $value);',
            '$patterns = ["#credential=.+#i", \'~ghp_[a-z0-9]+~\'];',
        ] as $source) {
            self::assertTrue($this->definesRedactionRules($source), $source);
        }

        self::assertFalse($this->definesRedactionRules('return app(Redactor::class)->redact($value, $context);'));
    }

    public function test_rule_detector_keeps_paths_and_subject_names_outside_pattern_definitions(): void
    {
        foreach ([
            '$path = \'/store/token\';',
            'is_file($root.\'/token\');',
            'preg_match(\'/\\A[0-9a-f]{32}\\z/D\', $credentialGeneration);',
            'preg_match(\'/\\A[!-~]+\\z/D\', $secret);',
            'if (preg_match(\'/\\A[0-9a-f]{32}\\z/D\', $value) !== 1) { throw new CredentialProjectionException("Invalid generation."); }',
            'str_replace("\\\\", "/", $credentialPath);',
            '$replace = str_replace(...);',
            '$path = \'/tests/Unit/Shared/Process/ProcessArchitectureTest.php\';',
        ] as $source) {
            self::assertFalse($this->definesRedactionRules($source), $source);
        }
    }

    private function definesRedactionRules(string $source): bool
    {
        if (preg_match('/(?:\breturn\s+|=>\s*|=\s*(?:\[\s*)?)[\'\"][^\'\"\r\n]*\[REDACTED:/i', $source) === 1
            || preg_match('/new\s+RedactionRule\s*\(/', $source) === 1) {
            return true;
        }

        // Inspect the search argument, never a subject variable or a later
        // exception. The parser is already a development dependency.
        if (preg_match('/\b(?:preg_(?:match(?:_all)?|replace)|str_replace)\s*\(/', $source) === 1) {
            try {
                $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse(
                    str_contains($source, '<?php') ? $source : '<?php '.$source,
                ) ?? [];
                $finder = new NodeFinder;
                foreach ($finder->findInstanceOf($nodes, FuncCall::class) as $call) {
                    if (! $call->name instanceof Name || $call->isFirstClassCallable()
                        || ! in_array(strtolower($call->name->toString()), ['preg_match', 'preg_match_all', 'preg_replace', 'str_replace'], true)) {
                        continue;
                    }
                    $searchName = strtolower($call->name->toString()) === 'str_replace' ? 'search' : 'pattern';
                    foreach ($call->getArgs() as $index => $argument) {
                        if (($argument->name === null && $index !== 0)
                            || ($argument->name !== null && $argument->name->toString() !== $searchName)) {
                            continue;
                        }
                        foreach ($finder->findInstanceOf([$argument->value], String_::class) as $literal) {
                            if ($this->namesSecretPattern($literal->value)) {
                                return true;
                            }
                        }
                    }
                }
            } catch (Error) {
                // JS/TS and Blade remain covered by the literal scan below.
            }
        }

        // Keep regex collections in every scanned language covered. A path
        // ending in /token has neither a closed regex nor valid modifiers.
        preg_match_all('/([\'\"])((?:\\\\.|(?!\1)[^\\\\])*)\1/s', $source, $literals, PREG_SET_ORDER);
        foreach ($literals as $literal) {
            $value = $literal[2];
            $delimiter = $value[0] ?? '';
            if (! in_array($delimiter, ['~', '#', '/'], true)) {
                continue;
            }
            $end = strrpos($value, $delimiter);
            if ($end !== false && $end > 0 && preg_match('/\A[imsxADSUXJu]*\z/D', substr($value, $end + 1)) === 1
                && $this->namesSecretPattern(substr($value, 1, $end - 1))) {
                return true;
            }
        }

        return false;
    }

    private function namesSecretPattern(string $value): bool
    {
        return preg_match('/secret|password|passwd|bearer|token|credential|sensitive[_-]?path|sk-|gh[opusr]_/i', $value) === 1;
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
