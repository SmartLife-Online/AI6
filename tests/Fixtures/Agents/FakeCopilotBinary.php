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
            $script = "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' '.escapeshellarg($scenario)." \"\$@\"\n";
        } else {
            $path .= '.cmd';
            $script = "@echo off\r\n\"".PHP_BINARY.'" "'.$fixture.'" "'.$scenario."\" %*\r\n";
        }
        Assert::assertNotFalse(file_put_contents($path, $script));
        Assert::assertTrue(chmod($path, 0755));

        return $path;
    }
}
