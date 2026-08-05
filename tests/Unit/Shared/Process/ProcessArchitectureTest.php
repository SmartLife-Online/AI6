<?php

namespace Tests\Unit\Shared\Process;

use PHPUnit\Framework\TestCase;

final class ProcessArchitectureTest extends TestCase
{
    public function test_productive_process_creation_exists_only_in_the_control_runner(): void
    {
        $root = dirname(__DIR__, 4).'/app';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $matches = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (is_string($contents) && preg_match('/(?:new\s+Process\s*\(|proc_open\s*\(|fromShellCommandline\s*\()/i', $contents) === 1) {
                $matches[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        self::assertSame([
            str_replace('\\', '/', dirname(__DIR__, 4).'/app/AI6/Shared/Process/ControlProcessRunner.php'),
        ], $matches);
    }

    public function test_effect_lock_has_only_the_two_approved_acquisition_call_sites(): void
    {
        $root = dirname(__DIR__, 4).'/app';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $matches = [];
        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'sh'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (! is_string($contents)) {
                continue;
            }

            $count = $file->getExtension() === 'php'
                ? substr_count($contents, 'flock(')
                : substr_count($contents, '/usr/bin/flock');
            for ($index = 0; $index < $count; $index++) {
                $matches[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($matches);
        self::assertSame([
            str_replace('\\', '/', $root.'/AI6/Shared/Process/EffectLock.php'),
            str_replace('\\', '/', $root.'/AI6/Shared/Process/control-process-wrapper.sh'),
        ], $matches);
    }
}
