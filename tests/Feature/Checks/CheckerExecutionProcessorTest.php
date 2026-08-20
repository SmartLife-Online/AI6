<?php

namespace Tests\Feature\Checks;

use App\AI6\Checks\CheckerExecutionProcessor;
use App\AI6\Checks\CheckerRuntimeConfiguration;
use App\AI6\Checks\CheckExecutionContractException;
use App\AI6\Checks\CheckExecutionRequest;
use App\AI6\Checks\CheckExecutionResultDocument;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckProfileConfigurationException;
use App\AI6\Checks\CheckProfileRegistry;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckTreeBinding;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ExecutionMailboxFactory;
use App\AI6\Shared\Process\ExecutionRole;
use App\AI6\Shared\Process\MailboxMessageType;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\TestCase;

final class CheckerExecutionProcessorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ai6-checker-processor-'.bin2hex(random_bytes(6));
        foreach (['input', 'output', 'workspace'] as $directory) {
            mkdir($this->root.'/'.$directory, 0700, true);
        }
        config([
            'ai6.runtime_role' => 'test',
            'ai6.execution_mailboxes.checker_root' => $this->root.'/input',
            'ai6.execution_mailboxes.checker_output_root' => $this->root.'/output',
            'ai6.checks.runtime.workspace_root' => $this->root.'/workspace',
            'ai6.process.policies.checker.working_roots' => [$this->root.'/workspace'],
            'ai6.process.policies.checker.allowed_executables' => [PHP_BINARY],
            'ai6.checks.profiles' => [
                'probe' => [
                    'program' => PHP_BINARY, 'arguments' => ['probe.php', $this->root.'/starts.log'], 'phases' => ['before_review'],
                    'working_directory' => 'tree', 'success_exit_codes' => [0],
                    'side_effects' => false, 'network' => false, 'mutates' => false,
                ],
            ],
        ]);
        foreach ([CheckerRuntimeConfiguration::class, ProcessPolicyRegistry::class, CheckProfileRegistry::class, ControlProcessRunner::class, CheckerExecutionProcessor::class, ExecutionMailboxFactory::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_a_claimed_request_is_copied_executed_once_and_returned_through_the_result_mailbox(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real checker process-group proof requires Linux.');
        }
        $executionId = 'execution-1';
        $source = $this->root.'/input/staged/'.$executionId;
        mkdir($source.'/tree', 0700, true);
        mkdir($source.'/baseline', 0700, true);
        $sourceBytes = "<?php file_put_contents(\$argv[1], \"start\\n\", FILE_APPEND); echo \"checker-ok\";\n";
        file_put_contents($source.'/tree/probe.php', $sourceBytes);
        $trees = $this->app->make(CheckTreeBinding::class);
        $request = new CheckExecutionRequest(
            $executionId, 'run-1', CheckPhase::BEFORE_REVIEW, 'probe',
            $trees->hash($source.'/tree'), $trees->hash($source.'/baseline'), time() + 60,
        );
        $mailbox = $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER);
        $delivery = CheckerExecutionProcessor::deliveryId($executionId);
        $mailbox->write(MailboxMessageType::REQUEST, 'check-run-1', $delivery, $request->toJson());

        $pulses = 0;
        self::assertTrue($this->app->make(CheckerExecutionProcessor::class)->processNext(str_repeat('a', 32), static function (string $_executionId) use (&$pulses): void {
            $pulses++;
        }));

        $message = $mailbox->read(MailboxMessageType::RESULT, 'check-run-1', $delivery, new RedactionContext('project-1', 'run-1', 'test'));
        $result = CheckExecutionResultDocument::fromJson($message->content);
        self::assertSame(CheckResultState::SUCCEEDED, $result->state, $result->redactedOutput.' / '.($result->reason ?? 'none'));
        self::assertSame($executionId, $result->executionId);
        self::assertSame('run-1', $result->runId);
        self::assertSame(CheckPhase::BEFORE_REVIEW, $result->phase);
        self::assertSame('probe', $result->profile);
        self::assertSame(str_repeat('a', 32), $result->checkerBootId);
        self::assertSame($request->sourceTreeSha256, $result->sourceTreeSha256);
        self::assertSame($request->sourceTreeSha256, $result->resultTreeSha256);
        self::assertSame($request->baselineSha256, $result->baselineSha256);
        self::assertSame($request->baselineSha256, $result->observedBaselineSha256);
        self::assertSame($sourceBytes, file_get_contents($source.'/tree/probe.php'));
        self::assertSame(['start'], file($this->root.'/starts.log', FILE_IGNORE_NEW_LINES));
        self::assertDirectoryDoesNotExist($this->root.'/workspace/'.$executionId);
        self::assertGreaterThanOrEqual(1, $pulses);
    }

    public function test_a_long_running_allowed_check_keeps_its_execution_heartbeat_fresh(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The periodic checker heartbeat proof requires Linux.');
        }
        config([
            'ai6.checks.runtime.poll_interval_seconds' => 1,
            'ai6.checks.runtime.heartbeat_interval_seconds' => 1,
            'ai6.checks.profiles.probe.arguments' => ['heartbeat.php'],
        ]);
        foreach ([CheckerRuntimeConfiguration::class, CheckProfileRegistry::class, CheckerExecutionProcessor::class] as $binding) {
            $this->app->forgetInstance($binding);
        }

        $executionId = 'execution-heartbeat';
        $source = $this->root.'/input/staged/'.$executionId;
        mkdir($source.'/tree', 0700, true);
        mkdir($source.'/baseline', 0700, true);
        file_put_contents($source.'/tree/heartbeat.php', "<?php\nsleep(3);\necho 'heartbeat-ok';\n");
        $trees = $this->app->make(CheckTreeBinding::class);
        $request = new CheckExecutionRequest(
            $executionId, 'run-heartbeat', CheckPhase::BEFORE_REVIEW, 'probe',
            $trees->hash($source.'/tree'), $trees->hash($source.'/baseline'), time() + 30,
        );
        $mailbox = $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER);
        $delivery = CheckerExecutionProcessor::deliveryId($executionId);
        $mailbox->write(MailboxMessageType::REQUEST, 'check-run-heartbeat', $delivery, $request->toJson());
        $pulses = [];
        $startedAt = time();

        self::assertTrue($this->app->make(CheckerExecutionProcessor::class)->processNext(
            str_repeat('c', 32),
            static function () use (&$pulses): void {
                $pulses[] = microtime(true);
            },
        ));

        $heartbeat = json_decode((string) file_get_contents($this->root.'/output/heartbeats/'.$executionId.'.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThanOrEqual(2, count($pulses));
        self::assertGreaterThan($startedAt, $heartbeat['recorded_at'] ?? 0);
        self::assertLessThanOrEqual(1, time() - ($heartbeat['recorded_at'] ?? 0));
        self::assertSame('0730', substr(sprintf('%o', fileperms($this->root.'/output/claims')), -4));
        self::assertSame('0730', substr(sprintf('%o', fileperms($this->root.'/output/heartbeats')), -4));
        self::assertSame('0730', substr(sprintf('%o', fileperms($this->root.'/output/executions')), -4));
        self::assertSame('0770', substr(sprintf('%o', fileperms($this->root.'/output/executions/'.$executionId)), -4));

        $result = CheckExecutionResultDocument::fromJson($mailbox->read(
            MailboxMessageType::RESULT,
            'check-run-heartbeat',
            $delivery,
            new RedactionContext('project-1', 'run-heartbeat', 'test'),
        )->content);
        self::assertSame(CheckResultState::SUCCEEDED, $result->state);
        self::assertGreaterThanOrEqual(2000, $result->durationMilliseconds);
    }

    public function test_a_baseline_mutation_returns_a_bound_failed_result(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real checker mutation proof requires Linux.');
        }
        config(['ai6.checks.profiles.probe.arguments' => ['mutate.php']]);
        foreach ([CheckProfileRegistry::class, CheckerExecutionProcessor::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $executionId = 'execution-baseline';
        $source = $this->root.'/input/staged/'.$executionId;
        mkdir($source.'/tree', 0700, true);
        mkdir($source.'/baseline', 0700, true);
        file_put_contents($source.'/tree/mutate.php', "<?php file_put_contents('../baseline/mutated', 'yes');\n");
        $trees = $this->app->make(CheckTreeBinding::class);
        $request = new CheckExecutionRequest(
            $executionId, 'run-1', CheckPhase::BEFORE_REVIEW, 'probe',
            $trees->hash($source.'/tree'), $trees->hash($source.'/baseline'), time() + 60,
        );
        $mailbox = $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER);
        $delivery = CheckerExecutionProcessor::deliveryId($executionId);
        $mailbox->write(MailboxMessageType::REQUEST, 'check-run-1', $delivery, $request->toJson());

        self::assertTrue($this->app->make(CheckerExecutionProcessor::class)->processNext(str_repeat('b', 32), static function (): void {}));

        $message = $mailbox->read(MailboxMessageType::RESULT, 'check-run-1', $delivery, new RedactionContext('project-1', 'run-1', 'test'));
        $result = CheckExecutionResultDocument::fromJson($message->content);
        self::assertSame(CheckResultState::FAILED, $result->state, $result->redactedOutput.' / '.($result->reason ?? 'none'));
        self::assertSame('baseline_mutated', $result->reason);
        self::assertSame($request->baselineSha256, $result->baselineSha256);
        self::assertNotSame($request->baselineSha256, $result->observedBaselineSha256);
    }

    public function test_a_workspace_leftover_blocks_before_the_request_is_claimed(): void
    {
        file_put_contents($this->root.'/workspace/foreign-rest', 'occupied');
        $request = new CheckExecutionRequest(
            'execution-blocked', 'run-1', CheckPhase::BEFORE_REVIEW, 'probe',
            str_repeat('a', 64), str_repeat('b', 64), time() + 60,
        );
        $mailbox = $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER);
        $delivery = CheckerExecutionProcessor::deliveryId($request->executionId);
        $mailbox->write(MailboxMessageType::REQUEST, 'check-run-1', $delivery, $request->toJson());

        try {
            $this->app->make(CheckerExecutionProcessor::class)->processNext(str_repeat('c', 32), static function (): void {});
            self::fail('The checker must block before claiming a request while a workspace rest exists.');
        } catch (CheckExecutionContractException $exception) {
            self::assertSame('checker_workspace_not_empty', $exception->reason);
        }

        $message = $mailbox->read(MailboxMessageType::REQUEST, 'check-run-1', $delivery, new RedactionContext('project-1', 'run-1', 'test'));
        self::assertEquals($request, CheckExecutionRequest::fromJson($message->content));
    }

    public function test_an_unknown_profile_is_rejected_before_any_profile_process_starts(): void
    {
        $request = $this->publishRequest('execution-unknown', 'unknown-profile', CheckPhase::BEFORE_REVIEW);

        try {
            $this->app->make(CheckerExecutionProcessor::class)->processNext(str_repeat('d', 32), static function (): void {});
            self::fail('An unknown checker profile must be rejected.');
        } catch (CheckProfileConfigurationException $exception) {
            self::assertSame('check_profile_unknown', $exception->reason);
        }

        self::assertFileDoesNotExist($this->root.'/starts.log');
        self::assertFileExists($this->root.'/output/claims/'.$request->executionId.'.json');
    }

    public function test_a_disallowed_phase_is_rejected_before_any_profile_process_starts(): void
    {
        $request = $this->publishRequest('execution-phase', 'probe', CheckPhase::FINAL);

        try {
            $this->app->make(CheckerExecutionProcessor::class)->processNext(str_repeat('e', 32), static function (): void {});
            self::fail('A profile outside its server-defined phase must be rejected.');
        } catch (CheckExecutionContractException $exception) {
            self::assertSame('check_request_profile_not_allowed', $exception->reason);
        }

        self::assertFileDoesNotExist($this->root.'/starts.log');
        self::assertFileExists($this->root.'/output/claims/'.$request->executionId.'.json');
    }

    private function publishRequest(string $executionId, string $profile, CheckPhase $phase): CheckExecutionRequest
    {
        $source = $this->root.'/input/staged/'.$executionId;
        mkdir($source.'/tree', 0700, true);
        mkdir($source.'/baseline', 0700, true);
        $trees = $this->app->make(CheckTreeBinding::class);
        $request = new CheckExecutionRequest(
            $executionId,
            'run-1',
            $phase,
            $profile,
            $trees->hash($source.'/tree'),
            $trees->hash($source.'/baseline'),
            time() + 60,
        );
        $this->app->make(ExecutionMailboxFactory::class)->forRole(ExecutionRole::CHECKER)->write(
            MailboxMessageType::REQUEST,
            'check-run-1',
            CheckerExecutionProcessor::deliveryId($executionId),
            $request->toJson(),
        );

        return $request;
    }
}
