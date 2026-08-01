<?php

namespace Tests\Feature\Shared\Runtime;

use App\AI6\Shared\Runtime\RuntimeSelfTestJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class RuntimeQueueTest extends TestCase
{
    private string $databasePath;

    private string $executionDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/ai6-runtime-queue-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        $this->databasePath = $base.'/database.sqlite';
        $this->executionDirectory = $base.'/executions';
        self::assertNotFalse(touch($this->databasePath));
        self::assertTrue(mkdir($this->executionDirectory, 0700));

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'sqlite',
            'queue.connections.database.retry_after' => 90,
            'queue.failed.database' => 'sqlite',
        ]);
        DB::purge('sqlite');
        putenv('AI6_EXECUTION_DIRECTORY='.$this->executionDirectory);

        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        putenv('AI6_EXECUTION_DIRECTORY');
        DB::purge('sqlite');

        foreach (glob($this->executionDirectory.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->executionDirectory);
        @unlink($this->databasePath);
        @unlink($this->databasePath.'-shm');
        @unlink($this->databasePath.'-wal');
        @rmdir(dirname($this->databasePath));
        parent::tearDown();
    }

    public function test_selftest_command_uses_database_queue_and_reports_the_job_reference(): void
    {
        self::assertSame(0, Artisan::call('ai6:runtime-selftest', ['key' => 'command-key']));
        self::assertSame(1, DB::table('jobs')->count());
        $output = Artisan::output();
        self::assertStringContainsString('Database Queue', $output);
        self::assertMatchesRegularExpression('/Laufzeittestjob \d+/', $output);

        $payload = DB::table('jobs')->value('payload');
        self::assertIsString($payload);
        $decodedPayload = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decodedPayload);
        self::assertSame(RuntimeSelfTestJob::class, $decodedPayload['displayName'] ?? null);
    }

    public function test_duplicate_and_redelivered_selftest_jobs_have_one_persistent_effect(): void
    {
        self::assertSame(0, Artisan::call('ai6:runtime-selftest', ['key' => 'same-key']));
        self::assertSame(0, Artisan::call('ai6:runtime-selftest', ['key' => 'same-key']));
        Queue::connection('database')->push(new RuntimeSelfTestJob('same-key'));
        self::assertSame(3, DB::table('jobs')->count());

        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--sleep' => 0,
            '--timeout' => 0,
            '--tries' => 3,
            '--stop-when-empty' => true,
        ]);

        self::assertSame(0, $exitCode, Artisan::output());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertCount(1, glob($this->executionDirectory.'/*') ?: []);
    }

    public function test_failing_job_retries_three_times_and_is_recorded_without_crashing_worker(): void
    {
        AlwaysFailingRuntimeJob::$attempts = 0;
        Queue::connection('database')->push(new AlwaysFailingRuntimeJob);

        $exitCode = Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--sleep' => 0,
            '--timeout' => 0,
            '--tries' => 3,
            '--stop-when-empty' => true,
        ]);

        self::assertSame(0, $exitCode, Artisan::output());
        self::assertSame(3, AlwaysFailingRuntimeJob::$attempts);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_sqlite_connection_applies_wal_foreign_keys_and_busy_timeout(): void
    {
        $connection = DB::connection('sqlite');

        self::assertSame('wal', strtolower((string) $connection->selectOne('pragma journal_mode')->journal_mode));
        self::assertSame(1, (int) $connection->selectOne('pragma foreign_keys')->foreign_keys);
        self::assertSame(5000, (int) $connection->selectOne('pragma busy_timeout')->timeout);
        self::assertGreaterThan(60, config('queue.connections.database.retry_after'));

        foreach (['migrations', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            self::assertTrue($connection->getSchemaBuilder()->hasTable($table));
        }
    }
}

final class AlwaysFailingRuntimeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public static int $attempts = 0;

    public int $tries = 3;

    public function __construct()
    {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(): never
    {
        self::$attempts++;

        throw new RuntimeException('Expected runtime retry failure.');
    }
}
