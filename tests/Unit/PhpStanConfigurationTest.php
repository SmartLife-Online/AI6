<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PhpStanConfigurationTest extends TestCase
{
    public function test_analysis_scope_level_and_baseline_policy_are_bound(): void
    {
        $configuration = file_get_contents($this->path('phpstan.neon'));
        self::assertNotFalse($configuration);
        self::assertDoesNotMatchRegularExpression('/^[ \t]*includes[ \t]*:/m', $configuration);

        self::assertSame('6', $this->parameterValue($configuration, 'level'));
        self::assertSame(
            ['app', 'bootstrap', 'routes', 'scripts', 'tests'],
            $this->listParameter($configuration, 'paths'),
        );

        $files = new Process(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], $this->path());
        $files->mustRun();
        $baselineFiles = array_values(array_filter(
            explode("\0", $files->getOutput()),
            static fn (string $path): bool => str_contains(strtolower(basename($path)), 'baseline'),
        ));

        self::assertSame([], $baselineFiles, 'Versionable PHPStan baseline files are forbidden.');
    }

    private function parameterValue(string $configuration, string $parameter): string
    {
        self::assertSame(1, preg_match('/^ {4}'.preg_quote($parameter, '/').':[ \t]*(\S+)[ \t]*$/m', $configuration, $matches));

        return $matches[1];
    }

    /** @return list<string> */
    private function listParameter(string $configuration, string $parameter): array
    {
        $lines = preg_split('/\R/', $configuration);
        self::assertIsArray($lines);
        $header = array_search('    '.$parameter.':', $lines, true);
        self::assertIsInt($header);

        $items = [];
        foreach (array_slice($lines, $header + 1) as $line) {
            if (preg_match('/^ {8}-[ \t]*(\S+)[ \t]*$/', $line, $matches) !== 1) {
                break;
            }

            $items[] = $matches[1];
        }
        self::assertNotSame([], $items);

        return $items;
    }

    private function path(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
