<?php

namespace Tests\Fixtures\Agents;

use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use Closure;
use PHPUnit\Framework\Assert;

/** Pump the actual mailbox consumer across the explicitly permitted containment seam. */
final class AgentMailboxFixture
{
    /**
     * @param  null|Closure(): void  $beforeClaim  Runs before each claim, e.g. to
     *                                             place a test credential projection
     *                                             into the staged home (AI6-033).
     */
    public static function drain(ExecutionJob $job, Closure $dispatch, ?Closure $beforeClaim = null): void
    {
        $boot = str_repeat('a', 32);
        for ($poll = 0; $poll < 32; $poll++) {
            $current = $job->fresh();
            $run = $current?->run()->first();
            if ($current?->state !== ExecutionJobState::WAITING
                || $run?->state !== RunState::RUNNING || $current->failure_code !== null) {
                self::stop($boot);

                return;
            }
            if ($beforeClaim !== null) {
                $beforeClaim();
            }
            Assert::assertTrue(app(AgentExecutionProcessor::class)->processNext($boot, static function (string $_id): void {}), 'A polling step must have one staged agent request.');
            Assert::assertFalse(app(AgentExecutionProcessor::class)->processNext($boot, static function (string $_id): void {}), 'The same request must not start a second provider turn.');
            Assert::assertTrue(app(RunOrchestrator::class)->resumeStep($current));
            $dispatch();
        }
        Assert::fail('The bounded agent mailbox fixture did not reach a result.');
    }

    private static function stop(string $boot): void
    {
        // End this in-process supervisor. Check every transport directory is
        // empty before removing it, so existing home-leak assertions retain
        // their meaning and can never hide an outstanding request or result.
        $input = AgentExecutionProcessor::inputRoot();
        $output = AgentExecutionProcessor::outputRoot();
        $lock = $input.'/.agent-lifecycle.lock';
        if (is_file($lock)) {
            Assert::assertSame(0, filesize($lock));
            Assert::assertTrue(unlink($lock));
        }
        $attestation = $output.'/attestations/agent.json';
        if (is_file($attestation)) {
            $document = json_decode(AgentExecutionProcessor::readBytes($attestation), true, 16, JSON_THROW_ON_ERROR);
            Assert::assertSame($boot, $document['agent_boot_id']);
            Assert::assertTrue(unlink($attestation));
        }
        foreach ([$input.'/requests', $output.'/results', $output.'/consumed', $output.'/claims', $output.'/heartbeats', $output.'/attestations'] as $directory) {
            if (is_dir($directory)) {
                Assert::assertSame(['.', '..'], scandir($directory), 'The supervisor left transport data behind: '.$directory);
                Assert::assertTrue(rmdir($directory));
            }
        }
    }
}
