<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentExecutionProcessor;
use Tests\TestCase;

final class AgentLifecycleLockTest extends TestCase
{
    public function test_the_read_only_supervisor_lock_serializes_a_second_process(): void
    {
        $root = sys_get_temp_dir().'/ai6-agent-lock-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700));
        config(['ai6.execution_mailboxes.agent_root' => $root]);
        AgentExecutionProcessor::initializeLifecycleLock();
        $lock = $root.'/.agent-lifecycle.lock';
        $released = $root.'/released';
        $code = <<<'PHP'
        $lock = fopen($argv[1], 'rb');
        if ($lock === false || !flock($lock, LOCK_EX)) { exit(2); }
        fwrite(STDOUT, "locked\n"); fflush(STDOUT);
        usleep(250000);
        file_put_contents($argv[2], 'released');
        flock($lock, LOCK_UN); fclose($lock);
        PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, $lock, $released], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertSame("locked\n", fgets($pipes[1]));
            self::assertTrue(AgentExecutionProcessor::withLifecycleLock(static fn (): bool => is_file($released)),
                'The worker entered before the supervisor released its publication lock.');
            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertTrue(unlink($released));
            self::assertTrue(unlink($lock));
            self::assertTrue(rmdir($root));
        }
    }
}
