<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

final class LockedInstallTest extends TestCase
{
    private string $composerArtifact;

    private string $php85Binary;

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->php85Binary = $this->requireFileFromEnvironment('AI6_PHP85_BINARY');
        $this->composerArtifact = $this->requireFileFromEnvironment('AI6_COMPOSER_PHAR');

        $version = new Process([$this->php85Binary, '-r', 'echo PHP_VERSION;']);
        $version->mustRun();
        self::assertMatchesRegularExpression(
            '/^8\.5\.\d+(?:[^\d].*)?$/',
            $version->getOutput(),
            'AI6_PHP85_BINARY must select a real PHP 8.5 runtime.',
        );

        $composerVersion = new Process([$this->php85Binary, $this->composerArtifact, '--version', '--no-ansi']);
        $composerVersion->mustRun();
        self::assertStringStartsWith('Composer version ', $composerVersion->getOutput());

        $this->temporaryDirectory = sys_get_temp_dir().'/ai6-locked-install-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryDirectory);
        parent::tearDown();
    }

    public function test_clean_install_uses_the_lock_and_has_no_implicit_side_effects(): void
    {
        $copy = $this->temporaryDirectory.'/project';
        $this->copyProject($copy);
        $lockPath = $copy.'/composer.lock';
        self::assertFileExists($lockPath);
        $lockHash = hash_file('sha256', $lockPath);
        self::assertIsString($lockHash);

        $this->composer(['validate', '--strict', '--no-interaction'], $copy)->mustRun();
        $install = $this->composer(['install', '--no-interaction', '--no-progress', '--prefer-dist'], $copy, 300);
        $install->mustRun();
        self::assertStringContainsString(
            'Installing dependencies from lock file',
            $install->getOutput().$install->getErrorOutput(),
        );
        self::assertSame($lockHash, hash_file('sha256', $lockPath));
        $this->composer(['check-platform-reqs', '--no-interaction'], $copy)->mustRun();

        self::assertFileExists($copy.'/vendor/autoload.php');
        self::assertFileDoesNotExist($copy.'/.env');
        self::assertFileDoesNotExist($copy.'/database/database.sqlite');
        self::assertDirectoryDoesNotExist($copy.'/database/migrations');
        self::assertDirectoryDoesNotExist($copy.'/storage/framework/cache/phpstan');

        $composer = json_decode((string) file_get_contents($copy.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('8.5.0', $composer['config']['platform']['php'] ?? null);
        self::assertDoesNotMatchRegularExpression(
            '/database\.sqlite|\bmigrate\b|queue:(?:listen|work|restart)|\btouch\s*\(/i',
            json_encode($composer['scripts'] ?? [], JSON_THROW_ON_ERROR),
        );
    }

    public function test_lock_resolved_for_php_86_is_rejected_by_php_85_platform(): void
    {
        $fixture = $this->temporaryDirectory.'/incompatible';
        $this->copyDirectory($this->path('tests/Fixtures/incompatible-lock'), $fixture);

        $composer = json_decode((string) file_get_contents($fixture.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^8.6', $composer['require']['php'] ?? null);
        self::assertSame('8.6.0', $composer['config']['platform']['php'] ?? null);

        $lock = json_decode((string) file_get_contents($fixture.'/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('^8.6', $lock['platform']['php'] ?? null);
        self::assertSame('8.6.0', $lock['platform-overrides']['php'] ?? null);
        $stringPackage = array_values(array_filter(
            $lock['packages'] ?? [],
            static fn (array $package): bool => ($package['name'] ?? null) === 'symfony/string',
        ));
        self::assertCount(1, $stringPackage);
        self::assertSame('>=8.6', $stringPackage[0]['require']['php']);

        $process = $this->composer(['check-platform-reqs', '--lock', '--no-interaction'], $fixture);
        self::assertNotSame(0, $process->run(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('8.6', $process->getOutput().$process->getErrorOutput());
    }

    private function copyProject(string $destination): void
    {
        mkdir($destination);
        $files = new Process(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], $this->path());
        $files->mustRun();

        foreach (array_filter(explode("\0", $files->getOutput())) as $relative) {
            $source = $this->path($relative);
            $target = $destination.'/'.$relative;
            self::assertFileExists($source);
            is_dir(dirname($target)) || mkdir(dirname($target), 0777, true);
            copy($source, $target);
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);
        foreach (array_diff(scandir($source) ?: [], ['.', '..']) as $entry) {
            $sourcePath = $source.'/'.$entry;
            $destinationPath = $destination.'/'.$entry;
            is_dir($sourcePath)
                ? $this->copyDirectory($sourcePath, $destinationPath)
                : copy($sourcePath, $destinationPath);
        }
    }

    /** @param list<string> $arguments */
    private function composer(array $arguments, string $workingDirectory, float $timeout = 60): Process
    {
        return new Process([
            $this->php85Binary,
            $this->composerArtifact,
            ...$arguments,
        ], $workingDirectory, timeout: $timeout);
    }

    private function requireFileFromEnvironment(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value, $name.' must explicitly select a local file.');
        self::assertNotSame('', $value, $name.' must explicitly select a local file.');

        $path = realpath($value);
        self::assertNotFalse($path, $name.' does not resolve to an existing file.');
        self::assertFileExists($path);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    private function path(string $path = ''): string
    {
        return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
    }
}
