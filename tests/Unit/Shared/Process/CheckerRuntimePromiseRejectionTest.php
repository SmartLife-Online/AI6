<?php

namespace Tests\Unit\Shared\Process;

use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessIsolationVerifier;
use App\AI6\Shared\Process\ProcessOutcome;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessRuntimeProbe;
use App\AI6\Shared\Redaction\RedactionContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CheckerRuntimePromiseRejectionTest extends TestCase
{
    #[DataProvider('promises')]
    public function test_each_false_checker_promise_rejects_before_the_profile_program(string $violated): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The checker promise decision requires the POSIX runtime role.');
        }

        $root = sys_get_temp_dir().'/ai6-checker-promise-'.bin2hex(random_bytes(8));
        foreach (['input', 'output', 'workspace', 'output/result', 'output/artifact'] as $directory) {
            self::assertTrue(mkdir($root.'/'.$directory, 0700, true));
        }
        $marker = $root.'/profile-started';
        $states = array_fill_keys(array_keys(iterator_to_array(self::promises())), true);
        $states[$violated] = false;
        $probe = new class($states) implements ProcessRuntimeProbe
        {
            /** @param array<string, bool> $states */
            public function __construct(private array $states) {}

            public function checkerRuntimePromises(): array
            {
                return $this->states;
            }

            public function mountOptions(string $path): array
            {
                return ['rw', 'nosuid', 'nodev', 'noexec'];
            }
        };

        config([
            'ai6.runtime_role' => 'checker',
            'ai6.execution_mailboxes.checker_root' => $root.'/input',
            'ai6.execution_mailboxes.checker_output_root' => $root.'/output',
            'ai6.checks.runtime.workspace_root' => $root.'/workspace',
            'ai6.process.policies.checker.working_roots' => [$root.'/workspace'],
            'ai6.process.policies.checker.allowed_executables' => [PHP_BINARY],
            'ai6.process.policies.checker.requires_process_group' => false,
        ]);
        $this->app->instance(ProcessIsolationBoundary::class, new ProcessIsolationVerifier($probe));
        foreach ([ProcessPolicyRegistry::class, ControlProcessRunner::class] as $binding) {
            $this->app->forgetInstance($binding);
        }

        try {
            $result = $this->app->make(ControlProcessRunner::class)->run(new ProcessRequest(
                [PHP_BINARY, '-r', 'file_put_contents($argv[1], "started");', $marker],
                $root.'/workspace',
                [],
                [],
                new RedactionContext('project', 'run', 'checker-promise'),
                policy: ProcessPolicyName::CHECKER,
                resultDirectory: $root.'/output/result',
                artifactDirectory: $root.'/output/artifact',
            ));

            self::assertSame(ProcessOutcome::START_REJECTED, $result->outcome);
            self::assertStringContainsString($violated, $result->errorOutput);
            self::assertFileDoesNotExist($marker);
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function promises(): iterable
    {
        foreach (['input_read_only', 'output_separate', 'workspace_private', 'container_read_only', 'network_isolated', 'namespace_tooling'] as $promise) {
            yield $promise => [$promise];
        }
    }
}
