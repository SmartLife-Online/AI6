<?php

namespace Tests\Feature\Shared\Runtime;

use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use App\AI6\Shared\Runtime\RuntimeSelfTestJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RuntimeSchedulerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

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
        self::assertCount(1, $events);
        self::assertTrue($events[0]->isRepeatable());
        self::assertSame(10, $events[0]->repeatSeconds);
        self::assertSame('ai6-runtime-scheduler', $events[0]->getSummaryForDisplay());

        $events[0]->run($this->app);
        $events[0]->run($this->app);

        self::assertTrue((new RuntimeHeartbeat($this->directory))->status('scheduler', 10)['healthy']);
        $jobs = Queue::pushed(RuntimeSelfTestJob::class);
        self::assertCount(2, $jobs);

        foreach ($jobs as $job) {
            self::assertSame('scheduler:0123456789abcdef0123456789abcdef', $job->idempotencyKey);
        }
    }
}
