<?php

namespace Tests\Unit\Shared\Process;

use App\AI6\Shared\Process\BlockedStartOutcome;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Process\EffectLockOutcome;
use App\AI6\Shared\Process\ProcessConfiguration;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EffectLockRuntimeSecurityTest extends TestCase
{
    public function test_preprovisioned_lock_objects_are_unreplaceable_for_the_runtime_identity(): void
    {
        $directory = $this->externalDirectory('AI6_EFFECT_LOCK_SECURITY_FIXTURE_DIRECTORY');
        $this->requireNonPrivilegedIdentity();
        $lock = new EffectLock($this->configuration($directory, 6));
        $path = $directory.'/lock-0001';
        $before = lstat($path);
        self::assertIsArray($before);

        $held = $lock->acquire('lock-0001', 100);
        self::assertTrue($held->acquired(), $held->message);
        self::assertFalse($this->withoutWarning(static fn (): bool => unlink($path)));
        self::assertFalse($this->withoutWarning(static fn (): bool => rename($path, $path.'.renamed')));
        self::assertFalse($this->withoutWarning(static fn (): int|false => file_put_contents($path.'.replacement', 'replacement')));
        self::assertFalse($this->withoutWarning(static fn (): int|false => file_put_contents($path, 'replacement')));

        clearstatcache(true, $path);
        $after = lstat($path);
        self::assertIsArray($after);
        self::assertSame($before['dev'], $after['dev']);
        self::assertSame($before['ino'], $after['ino']);
        self::assertSame(EffectLockOutcome::UNAVAILABLE, $lock->acquire('lock-0001', 30)->outcome);

        self::assertSame(EffectLockOutcome::CONFLICT, $lock->acquire('../lock-0001')->outcome);
        self::assertSame(EffectLockOutcome::CONFLICT, $lock->acquire('lock-0002')->outcome);
        self::assertSame(EffectLockOutcome::CONFLICT, $lock->acquire('lock-0003')->outcome);
        self::assertSame(EffectLockOutcome::CONFLICT, $lock->acquire('lock-0004')->outcome);
        self::assertSame(EffectLockOutcome::CONFIGURATION_ERROR, $lock->acquire('lock-0005')->outcome);
        self::assertFileDoesNotExist($directory.'/lock-0005');

        $held->handle?->release();
    }

    public function test_group_sigkill_releases_an_unchanged_lock_on_a_separate_mount(): void
    {
        $directory = $this->externalDirectory('AI6_EFFECT_LOCK_SECONDARY_FIXTURE_DIRECTORY');
        $this->requireNonPrivilegedIdentity();
        $directoryStat = stat($directory);
        $temporaryStat = stat(sys_get_temp_dir());
        self::assertIsArray($directoryStat);
        self::assertIsArray($temporaryStat);
        self::assertNotSame($temporaryStat['dev'], $directoryStat['dev'], 'The secondary lock fixture must be a separate mount.');

        $configuration = $this->configuration($directory, 1);
        $runner = new ControlProcessRunner($configuration, $this->redactor(), new EffectLock($configuration));
        $pidFile = sys_get_temp_dir().'/ai6-lock-child-'.bin2hex(random_bytes(6));
        $before = lstat($directory.'/lock-0001');
        self::assertIsArray($before);
        $code = <<<'PHP'
$child = proc_open([PHP_BINARY, '-r', 'while (true) { usleep(100000); }'], [], $pipes);
$status = is_resource($child) ? proc_get_status($child) : false;
if (! is_array($status) || ! is_int($status['pid'])) { exit(3); }
file_put_contents($argv[1], (string) $status['pid']);
while (true) { usleep(100000); }
PHP;

        try {
            $start = $runner->startBlocked($this->request([PHP_BINARY, '-r', $code, $pidFile]), 'lock-0001');
            self::assertSame(BlockedStartOutcome::READY, $start->outcome, $start->message);
            self::assertNotNull($start->process);
            $processId = $start->process->processId;
            $start->process->release();
            $this->waitForFile($pidFile);

            (new Process(['/usr/bin/kill', '-KILL', '--', '-'.$processId]))->mustRun();
            $start->process->wait();

            clearstatcache(true, $directory.'/lock-0001');
            $after = lstat($directory.'/lock-0001');
            self::assertIsArray($after);
            self::assertSame($before['dev'], $after['dev']);
            self::assertSame($before['ino'], $after['ino']);

            $next = $runner->startBlocked($this->request([PHP_BINARY, '-r', 'echo "released";']), 'lock-0001');
            self::assertTrue($next->ready(), $next->message);
            $next->process?->release();
            self::assertSame('released', $next->process?->wait()->output);
        } finally {
            @unlink($pidFile);
        }
    }

    private function externalDirectory(string $variable): string
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The external effect-lock proof requires Linux.');
        }

        $directory = getenv($variable);
        if (! is_string($directory) || $directory === '') {
            self::markTestSkipped($variable.' is not configured.');
        }

        self::assertDirectoryExists($directory);
        $real = realpath($directory);
        self::assertIsString($real);

        return $real;
    }

    private function requireNonPrivilegedIdentity(): void
    {
        $self = stat('/proc/self');
        self::assertIsArray($self);
        self::assertNotSame(0, $self['uid'], 'The lock identity proof is invalid when run as root.');
    }

    private function configuration(string $directory, int $count): ProcessConfiguration
    {
        $owner = fileowner($directory);
        self::assertIsInt($owner);

        return new ProcessConfiguration(
            10,
            4096,
            100,
            2,
            dirname(__DIR__, 4).'/app/AI6/Shared/Process/control-process-wrapper.sh',
            '/usr/bin/dash',
            '/usr/bin/setsid',
            '/usr/bin/kill',
            $directory,
            $count,
            500,
            $owner,
        );
    }

    /** @param non-empty-list<string> $command */
    private function request(array $command): ProcessRequest
    {
        return new ProcessRequest(
            $command,
            sys_get_temp_dir(),
            [],
            [],
            new RedactionContext('project-1', 'run-1', 'effect-lock-runtime-test'),
        );
    }

    private function redactor(): Redactor
    {
        $ring = new RedactionKeyring('test-v1', [
            'test-v1' => ['version' => 1, 'key' => str_repeat('r', 32)],
        ]);

        return new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator($ring),
        );
    }

    private function waitForFile(string $path): void
    {
        $deadline = microtime(true) + 2;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }

        self::assertFileExists($path);
    }

    private function withoutWarning(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
