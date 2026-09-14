<?php

namespace Tests\Fixtures\Agents;

use PHPUnit\Framework\Assert;

/** Deterministic prompt-file/NDJSON CLI fixture; never contacts xAI or reads host credentials. */
final class FakeGrokBinary
{
    public static function create(string $directory, string $scenario = 'success'): string
    {
        if (! is_dir($directory)) {
            Assert::assertTrue(mkdir($directory, 0700, true));
        }
        $fixture = str_replace('\\', '/', __DIR__.'/fake-grok.php');
        $path = $directory.'/grok-'.bin2hex(random_bytes(5));
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
