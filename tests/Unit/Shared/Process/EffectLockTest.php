<?php

namespace Tests\Unit\Shared\Process;

use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\EffectLockOutcome;
use App\AI6\Shared\Process\ProcessConfiguration;
use PHPUnit\Framework\TestCase;

final class EffectLockTest extends TestCase
{
    private ?string $directory = null;

    public function test_it_rejects_unknown_traversing_and_mutable_lock_objects_without_creating_files(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX lock identity checks require a POSIX filesystem.');
        }

        $lock = $this->lock();
        self::assertSame(EffectLockOutcome::CONFLICT, $lock->acquire('../lock-0001')->outcome);
        self::assertSame(EffectLockOutcome::CONFLICT, $lock->acquire('lock-0002')->outcome);
        self::assertSame(EffectLockOutcome::CONFLICT, $lock->acquire('lock-0003')->outcome);
        self::assertSame(EffectLockOutcome::CONFLICT, $lock->acquire('lock-0004')->outcome);
        self::assertSame(EffectLockOutcome::CONFIGURATION_ERROR, $lock->acquire('lock-0005')->outcome);
        self::assertFileDoesNotExist($this->directory.'/lock-0005');
    }

    public function test_direct_acquisitions_share_one_os_lock_and_timeout_result(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX lock concurrency checks require a POSIX filesystem.');
        }

        $lock = $this->lock();
        $first = $lock->acquire('lock-0001', 100);
        self::assertTrue($first->acquired());
        self::assertSame(EffectLockOutcome::UNAVAILABLE, $lock->acquire('lock-0001', 30)->outcome);

        $first->handle?->release();
        self::assertTrue($lock->acquire('lock-0001', 100)->acquired());
    }

    public function test_it_rejects_a_configured_owner_equal_to_the_runtime_identity(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX lock ownership checks require a POSIX filesystem.');
        }

        $runtime = stat('/proc/self');
        self::assertIsArray($runtime);
        $lock = new EffectLock($this->configuration($this->externalDirectory(), (int) $runtime['uid']));

        $result = $lock->validate('lock-0001');
        self::assertSame(EffectLockOutcome::CONFIGURATION_ERROR, $result->outcome);
        self::assertStringContainsString('must differ', $result->message);
    }

    private function lock(): EffectLock
    {
        $this->directory = $this->externalDirectory();
        $owner = fileowner($this->directory);
        self::assertIsInt($owner);

        return new EffectLock($this->configuration($this->directory, $owner));
    }

    private function externalDirectory(): string
    {
        $directory = getenv('AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY');
        if (! is_string($directory) || $directory === '') {
            self::markTestSkipped('AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY is not configured with privileged fixtures.');
        }

        $real = realpath($directory);
        self::assertIsString($real);

        return $real;
    }

    private function configuration(string $directory, int $owner): ProcessConfiguration
    {
        return new ProcessConfiguration(
            5,
            4096,
            100,
            2,
            dirname(__DIR__, 4).'/app/AI6/Shared/Process/control-process-wrapper.sh',
            '/usr/bin/dash',
            '/usr/bin/setsid',
            '/usr/bin/kill',
            $directory,
            6,
            100,
            $owner,
        );
    }
}
