<?php

namespace Tests\Fixtures\Agents;

use PHPUnit\Framework\Assert;

/** Deterministic stdin/text CLI fixture; never contacts GitHub or reads host credentials. */
final class FakeCopilotBinary
{
    public static function create(string $directory, string $scenario = 'success'): string
    {
        if (! is_dir($directory)) {
            Assert::assertTrue(mkdir($directory, 0700, true));
        }
        $fixture = str_replace('\\', '/', __DIR__.'/fake-copilot.php');
        $path = $directory.'/copilot-'.bin2hex(random_bytes(5));
        if (DIRECTORY_SEPARATOR === '/') {
            $source = (string) file_get_contents($fixture);
            $source = preg_replace('/^<\?php/', '<?php'."\n".'$argv = [$argv[0], '.var_export($scenario, true).', ...array_slice($argv, 1)];', $source, 1);
            $script = '#!'.PHP_BINARY."\n".$source;
        } else {
            $path .= '.cmd';
            $script = "@echo off\r\n\"".PHP_BINARY.'" "'.$fixture.'" "'.$scenario."\" %*\r\n";
        }
        Assert::assertNotFalse(file_put_contents($path, $script));
        Assert::assertTrue(chmod($path, 0755));

        return $path;
    }
}
