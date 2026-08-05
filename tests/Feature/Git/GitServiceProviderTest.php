<?php

namespace Tests\Feature\Git;

use App\AI6\Git\HardenedGitRunner;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use Tests\TestCase;

final class GitServiceProviderTest extends TestCase
{
    public function test_process_lock_and_git_boundaries_are_singletons(): void
    {
        $wrapper = config('ai6.process.wrapper_script');
        self::assertIsString($wrapper);
        if (DIRECTORY_SEPARATOR === '/' && is_writable($wrapper)) {
            self::markTestSkipped('Wrapper ist für die Laufzeitidentität schreibbar.');
        }

        self::assertSame($this->app->make(ControlProcessRunner::class), $this->app->make(ControlProcessRunner::class));
        self::assertSame($this->app->make(EffectLock::class), $this->app->make(EffectLock::class));
        self::assertSame($this->app->make(HardenedGitRunner::class), $this->app->make(HardenedGitRunner::class));
    }
}
