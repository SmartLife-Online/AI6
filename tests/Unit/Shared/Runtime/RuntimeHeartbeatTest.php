<?php

namespace Tests\Unit\Shared\Runtime;

use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

final class RuntimeHeartbeatTest extends TestCase
{
    private const BOOT_ID = '0123456789abcdef0123456789abcdef';

    private const WORKER_HEARTBEAT_SERVICE = 'ai6.runtime.heartbeat.worker';

    private string $rootDirectory;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDirectory = sys_get_temp_dir().'/ai6-heartbeat-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->rootDirectory, 0700));
        $this->directory = $this->rootDirectory.'/worker';
        self::assertTrue(mkdir($this->directory, 0700));
        self::assertNotFalse(file_put_contents($this->directory.'/boot-id', self::BOOT_ID."\n"));
        putenv('AI6_HEARTBEAT_DIRECTORY='.$this->directory);
        putenv('AI6_HEARTBEAT_MAX_AGE=10');
    }

    protected function tearDown(): void
    {
        putenv('AI6_HEARTBEAT_DIRECTORY');
        putenv('AI6_HEARTBEAT_MAX_AGE');

        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->directory);
        @rmdir($this->rootDirectory);
        parent::tearDown();
    }

    public function test_status_rejects_missing_stale_future_and_foreign_boot_heartbeats(): void
    {
        $heartbeat = new RuntimeHeartbeat($this->directory);

        self::assertSame(['healthy' => false, 'age' => null], $heartbeat->status('worker', 10, 100));

        $heartbeat->write('worker', 95);
        self::assertSame(['healthy' => true, 'age' => 5], $heartbeat->status('worker', 10, 100));
        self::assertSame(['healthy' => false, 'age' => 15], $heartbeat->status('worker', 10, 110));
        self::assertSame(['healthy' => false, 'age' => -1], $heartbeat->status('worker', 10, 94));

        self::assertNotFalse(file_put_contents($this->directory.'/boot-id', str_repeat('f', 32)."\n"));
        self::assertSame(['healthy' => false, 'age' => 5], $heartbeat->status('worker', 10, 100));
    }

    public function test_worker_looping_listener_updates_the_idle_heartbeat(): void
    {
        putenv('AI6_HEARTBEAT_DIRECTORY='.RuntimeHeartbeat::WORKER_DIRECTORY);
        self::assertFalse($this->app->bound(RuntimeHeartbeat::class));
        self::assertTrue($this->app->bound(self::WORKER_HEARTBEAT_SERVICE));
        $heartbeat = new RuntimeHeartbeat($this->directory);
        $this->app->instance(self::WORKER_HEARTBEAT_SERVICE, $heartbeat);
        self::assertSame($heartbeat, $this->app->make(self::WORKER_HEARTBEAT_SERVICE));

        try {
            Event::dispatch(new Looping('database', 'default'));
        } finally {
            putenv('AI6_HEARTBEAT_DIRECTORY='.$this->directory);
        }

        self::assertTrue($heartbeat->status('worker', 10)['healthy']);
    }

    public function test_worker_looping_listener_rejects_a_lookalike_worker_heartbeat_target(): void
    {
        self::assertStringEndsWith('/worker', str_replace('\\', '/', $this->directory));
        self::assertNotSame(RuntimeHeartbeat::WORKER_DIRECTORY, $this->directory);

        Event::dispatch(new Looping('database', 'default'));

        self::assertFileDoesNotExist($this->directory.'/heartbeat.json');
    }

    public function test_worker_looping_listener_ignores_a_non_worker_heartbeat_target(): void
    {
        $schedulerDirectory = $this->rootDirectory.'/scheduler';
        self::assertTrue(mkdir($schedulerDirectory, 0700));
        self::assertNotFalse(file_put_contents($schedulerDirectory.'/boot-id', self::BOOT_ID."\n"));
        putenv('AI6_HEARTBEAT_DIRECTORY='.$schedulerDirectory);

        try {
            Event::dispatch(new Looping('database', 'default'));
            self::assertFileDoesNotExist($schedulerDirectory.'/heartbeat.json');
        } finally {
            putenv('AI6_HEARTBEAT_DIRECTORY='.$this->directory);
            @unlink($schedulerDirectory.'/boot-id');
            @rmdir($schedulerDirectory);
        }
    }

    public function test_health_command_does_not_resolve_a_database_connection(): void
    {
        (new RuntimeHeartbeat($this->directory))->write('worker');
        $originalDefault = DB::getDefaultConnection();

        DB::extend('forbidden', static function (): never {
            throw new RuntimeException('The health command requested a database connection.');
        });
        DB::setDefaultConnection('forbidden');

        try {
            self::assertSame(0, Artisan::call('ai6:runtime-health', ['--role' => 'worker']));
            self::assertStringContainsString('Rolle worker ist gesund', Artisan::output());
            self::assertStringNotContainsString($this->directory, Artisan::output());
        } finally {
            DB::setDefaultConnection($originalDefault);
        }
    }

    public function test_health_command_returns_failure_for_missing_stale_and_foreign_boot_ids(): void
    {
        self::assertSame(1, Artisan::call('ai6:runtime-health', ['--role' => 'worker']));

        (new RuntimeHeartbeat($this->directory))->write('worker', time() - 20);
        self::assertSame(1, Artisan::call('ai6:runtime-health', ['--role' => 'worker']));

        (new RuntimeHeartbeat($this->directory))->write('worker');
        self::assertNotFalse(file_put_contents($this->directory.'/boot-id', str_repeat('a', 32)."\n"));
        self::assertSame(1, Artisan::call('ai6:runtime-health', ['--role' => 'worker']));
    }
}
