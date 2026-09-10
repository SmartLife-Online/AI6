<?php

namespace Tests\Fixtures\Agents;

use App\AI6\Agents\CodexCliConfiguration;
use PHPUnit\Framework\Assert;

/**
 * Materialize the deterministic fake Codex CLI as an executable the agent
 * process policy can name: a `#!/bin/sh` wrapper on POSIX, a `.cmd` wrapper
 * on Windows. Scenario and version line are baked into the wrapper because
 * the adapter passes the child only its allowlisted environment.
 *
 * Both wrappers pass standard input through unchanged, which is where the
 * prompt bytes travel since the adapter uses the `-` prompt argument. The
 * `%*` truncation of cmd.exe at the first newline therefore no longer
 * touches the prompt; only the option list goes over the command line.
 */
final class FakeCodexBinary
{
    public const VERSION_LINE = 'codex-cli 0.129.0-alpha.15';

    public const PINNED_VERSION = '0.129.0-alpha.15';

    /**
     * The sandbox and tool-network proof a test instance asserts for the
     * pinned version, bound to the platform the test actually runs on. In
     * production this value comes from AI6-033/MG-01, never from a fixture.
     */
    public static function sandboxProof(): string
    {
        return self::PINNED_VERSION.':'.CodexCliConfiguration::runtimePlatform();
    }

    public static function create(string $directory, string $scenario = 'success', string $versionLine = self::VERSION_LINE): string
    {
        if (! is_dir($directory)) {
            Assert::assertTrue(mkdir($directory, 0700, true));
        }
        $fixture = str_replace('\\', '/', __DIR__.'/fake-codex.php');
        $name = 'codex-'.preg_replace('/[^a-z0-9_-]/', '-', $scenario).'-'.bin2hex(random_bytes(3));
        if (DIRECTORY_SEPARATOR === '/') {
            $path = $directory.'/'.$name;
            $script = "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' '
                .escapeshellarg('--scenario='.$scenario).' '.escapeshellarg('--version-string='.$versionLine)." -- \"\$@\"\n";
            Assert::assertNotFalse(file_put_contents($path, $script));
            Assert::assertTrue(chmod($path, 0755));

            return $path;
        }
        $path = $directory.'/'.$name.'.cmd';
        $script = "@echo off\r\n\"".PHP_BINARY.'" "'.str_replace('/', '\\', $fixture).'" "--scenario='.$scenario
            .'" "--version-string='.$versionLine."\" -- %*\r\n";
        Assert::assertNotFalse(file_put_contents($path, $script));

        return str_replace('\\', '/', $path);
    }

    /**
     * The observation the fake wrote next to its TMPDIR in the adapter's scratch directory.
     *
     * @return array<string, mixed>|null
     */
    public static function observation(string $resultDirectory): ?array
    {
        $path = $resultDirectory.'/codex/observation.json';
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        Assert::assertIsArray($decoded);

        return $decoded;
    }
}
