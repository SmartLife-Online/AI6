<?php

namespace Tests\Feature\Shared\Runtime;

use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use App\AI6\Shared\Runtime\RuntimeSelfTestJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RuntimeSchedulerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());

        $this->directory = sys_get_temp_dir().'/ai6-runtime-scheduler-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        self::assertNotFalse(file_put_contents($this->directory.'/boot-id', '0123456789abcdef0123456789abcdef'."\n"));
        putenv('AI6_HEARTBEAT_DIRECTORY='.$this->directory);
    }

    protected function tearDown(): void
    {
        putenv('AI6_HEARTBEAT_DIRECTORY');

        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($this->directory);
        parent::tearDown();
    }

    public function test_ten_second_task_updates_heartbeat_and_reuses_boot_scoped_selftest_key(): void
    {
        Queue::fake();
        $events = $this->app->make(Schedule::class)->events();
        self::assertCount(5, $events);
        $eventsByName = collect($events)->keyBy(static fn ($event): string => $event->getSummaryForDisplay());
        $runtime = $eventsByName->get('ai6-runtime-scheduler');
        $reconciler = $eventsByName->get('ai6-control-operation-reconciler');
        $stepReconciler = $eventsByName->get('ai6-run-step-reconciler');
        $queueReevaluation = $eventsByName->get('ai6-queue-trusted-binding-reevaluation');
        $retention = $eventsByName->get('ai6-run-retention');
        self::assertNotNull($runtime);
        self::assertNotNull($reconciler);
        self::assertNotNull($stepReconciler);
        self::assertNotNull($queueReevaluation);
        self::assertNotNull($retention);
        self::assertTrue($runtime->isRepeatable());
        self::assertSame(10, $runtime->repeatSeconds);
        self::assertTrue($reconciler->isRepeatable());
        self::assertSame(10, $reconciler->repeatSeconds);
        self::assertTrue($stepReconciler->isRepeatable());
        self::assertSame(10, $stepReconciler->repeatSeconds);
        self::assertTrue($queueReevaluation->isRepeatable());
        self::assertSame(10, $queueReevaluation->repeatSeconds);
        // AI6-031: the retention run is the one named hourly entry; the four
        // ten-second entries above stay unchanged.
        self::assertFalse($retention->isRepeatable());
        self::assertSame('0 * * * *', $retention->expression);

        $runtime->run($this->app);
        $runtime->run($this->app);
        $reconciler->run($this->app);
        $stepReconciler->run($this->app);
        $queueReevaluation->run($this->app);
        $retention->run($this->app);
        $retention->run($this->app);

        self::assertTrue((new RuntimeHeartbeat($this->directory))->status('scheduler', 10)['healthy']);
        $jobs = Queue::pushed(RuntimeSelfTestJob::class);
        self::assertCount(2, $jobs);

        foreach ($jobs as $job) {
            self::assertSame('scheduler:0123456789abcdef0123456789abcdef', $job->idempotencyKey);
        }
    }
}
