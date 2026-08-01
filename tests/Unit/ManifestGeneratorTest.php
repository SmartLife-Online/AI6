<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ManifestGeneratorTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().'/ai6-manifest-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_generator_is_deterministic_and_check_detects_manifest_drift(): void
    {
        $first = $this->temporaryDirectory.'/first.yaml';
        $second = $this->temporaryDirectory.'/second.yaml';

        $this->runGenerator(['--output='.$first])->mustRun();
        $this->runGenerator(['--output='.$second])->mustRun();

        self::assertFileEquals($first, $second);
        self::assertFileEquals($this->path('docs/AI6_TICKET_MANIFEST.yaml'), $first);
        $this->runGenerator(['--check'])->mustRun();

        file_put_contents($first, "# controlled drift\n", FILE_APPEND);
        self::assertSame(1, $this->runGenerator(['--check', '--output='.$first])->run());
    }

    public function test_check_detects_blueprint_metadata_drift_in_a_plan_copy(): void
    {
        $plan = file_get_contents($this->path('docs/AI6_IMPLEMENTATION_PLAN.md'));
        self::assertNotFalse($plan);
        $changed = str_replace(
            '### AI6-001 — Laravel-Grundgerüst und Qualitätsbaseline',
            '### AI6-001 — Kontrollierte Drift',
            $plan,
            $replacements,
        );
        self::assertSame(1, $replacements);

        $planPath = $this->temporaryDirectory.'/plan.md';
        file_put_contents($planPath, $changed);

        self::assertSame(1, $this->runGenerator([
            '--check',
            '--plan='.$planPath,
            '--output='.$this->path('docs/AI6_TICKET_MANIFEST.yaml'),
        ])->run());
    }

    /** @param list<string> $arguments */
    private function runGenerator(array $arguments): Process
    {
        return new Process([
            PHP_BINARY,
            $this->path('scripts/generate-ticket-manifest.php'),
            ...$arguments,
        ], $this->path(), timeout: 30);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = array_diff(scandir($directory) ?: [], ['.', '..']);
        foreach ($entries as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }

    private function path(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
