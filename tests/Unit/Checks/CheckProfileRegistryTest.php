<?php

namespace Tests\Unit\Checks;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckProfileAllowlist;
use App\AI6\Checks\CheckProfileConfigurationException;
use App\AI6\Checks\CheckProfileRegistry;
use App\AI6\Checks\CheckProfileWorkingDirectory;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TC-02: a forbidden profile is a named configuration error, never something executed.
 * TC-03: the allowed project names come from this registry and from nowhere else.
 */
final class CheckProfileRegistryTest extends TestCase
{
    /** @return array{program: string, arguments: list<string>, phases: list<string>, working_directory: string, success_exit_codes: list<int>, side_effects: bool, network: bool, mutates: bool} */
    private static function validDefinition(): array
    {
        return [
            'program' => PHP_BINARY,
            'arguments' => ['artisan', 'test'],
            'phases' => ['before_review'],
            'working_directory' => 'tree',
            'success_exit_codes' => [0],
            'side_effects' => false,
            'network' => false,
            'mutates' => false,
        ];
    }

    /** @param array<string, mixed> $profiles */
    private function registryFor(array $profiles): CheckProfileRegistry
    {
        config(['ai6.checks.profiles' => $profiles]);

        return CheckProfileRegistry::fromConfiguredValues();
    }

    public function test_the_shipped_profiles_declare_program_arguments_phases_and_metadata(): void
    {
        $registry = CheckProfileRegistry::fromConfiguredValues();
        self::assertSame(['git-diff-check', 'php-all', 'php-targeted'], $registry->names());

        $targeted = $registry->get('php-targeted');
        self::assertSame(PHP_BINARY, $targeted->program);
        self::assertSame(['artisan', 'test', '--compact'], $targeted->arguments);
        self::assertSame([CheckPhase::BEFORE_REVIEW], $targeted->phases);
        self::assertSame(CheckProfileWorkingDirectory::TREE, $targeted->workingDirectory);
        self::assertFalse($targeted->requiresNetwork);
        self::assertFalse($targeted->mutatesTree);
        self::assertTrue($targeted->allowsPhase(CheckPhase::BEFORE_REVIEW));
        self::assertFalse($targeted->allowsPhase(CheckPhase::FINAL));

        // The repo-less --no-index form is the only one that works on an export
        // without Git metadata, and its clean exit code is 1, not 0.
        $diff = $registry->get('git-diff-check');
        self::assertSame(['--no-pager', 'diff', '--check', '--no-index', '--', 'baseline', 'tree'], $diff->arguments);
        self::assertSame(CheckProfileWorkingDirectory::BATCH, $diff->workingDirectory);
        self::assertSame([0, 1], $diff->successExitCodes);
    }

    /** Every shipped program is also allowed by the checker process policy. */
    public function test_every_profile_program_is_allowed_by_the_checker_process_policy(): void
    {
        $allowed = ProcessPolicyRegistry::fromConfiguredValues()->get(ProcessPolicyName::CHECKER)->allowedExecutables;
        self::assertNotContains('*', $allowed);

        foreach (CheckProfileRegistry::fromConfiguredValues()->names() as $name) {
            self::assertContains(CheckProfileRegistry::fromConfiguredValues()->get($name)->program, $allowed);
        }
    }

    /**
     * TC-02.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function forbiddenProfiles(): array
    {
        return [
            'shell string instead of an argument list' => [
                '/bin/sh -c "php artisan test"',
                'check_profile_shell_string',
            ],
            'shell interpreter as program' => [
                ['program' => '/bin/sh'] + self::validDefinition(),
                'check_profile_shell_string',
            ],
            'arguments as a string' => [
                ['arguments' => 'artisan test'] + self::validDefinition(),
                'check_profile_shell_string',
            ],
            'inline code flag' => [
                ['arguments' => ['-r', 'echo 1;']] + self::validDefinition(),
                'check_profile_inline_code',
            ],
            'unknown program' => [
                ['program' => '/usr/bin/definitely-not-allowed'] + self::validDefinition(),
                'check_profile_program_unknown',
            ],
            // An embedded argument cannot match the checker policy's allowlist,
            // which is a strict equality check on the whole program string.
            'program with embedded arguments' => [
                ['program' => PHP_BINARY.' artisan'] + self::validDefinition(),
                'check_profile_program_unknown',
            ],
            'program with a control character' => [
                ['program' => PHP_BINARY."\n"] + self::validDefinition(),
                'check_profile_program_invalid',
            ],
            'missing metadata' => [
                array_diff_key(self::validDefinition(), ['mutates' => null]),
                'check_profile_metadata_missing',
            ],
            'non boolean metadata' => [
                ['mutates' => 'no'] + self::validDefinition(),
                'check_profile_metadata_missing',
            ],
            'unknown phase' => [
                ['phases' => ['after_review']] + self::validDefinition(),
                'check_profile_phases_invalid',
            ],
            'no phase at all' => [
                ['phases' => []] + self::validDefinition(),
                'check_profile_phases_invalid',
            ],
            'unknown working directory' => [
                ['working_directory' => '/etc'] + self::validDefinition(),
                'check_profile_working_directory_invalid',
            ],
            'missing success exit codes' => [
                ['success_exit_codes' => []] + self::validDefinition(),
                'check_profile_exit_codes_invalid',
            ],
        ];
    }

    #[DataProvider('forbiddenProfiles')]
    public function test_a_forbidden_profile_is_a_named_configuration_error(mixed $definition, string $reason): void
    {
        try {
            $this->registryFor(['probe' => $definition]);
            self::fail('The forbidden profile was accepted.');
        } catch (CheckProfileConfigurationException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    /** A rejected definition never becomes an allowed project name either. */
    public function test_a_rejected_profile_never_reaches_the_project_allowlist(): void
    {
        config(['ai6.checks.profiles' => ['probe' => '/bin/sh -c "true"']]);

        $this->expectException(CheckProfileConfigurationException::class);
        CheckProfileAllowlist::fromConfiguredValues();
    }

    /** TC-03: exactly one source of allowed names. */
    public function test_the_project_allowlist_is_derived_from_the_registry(): void
    {
        $registry = $this->registryFor(['only-one' => self::validDefinition()]);
        $allowlist = CheckProfileAllowlist::fromRegistry($registry);

        self::assertSame($registry->names(), $allowlist->profiles());
        self::assertTrue($allowlist->allows('only-one'));
        self::assertFalse($allowlist->allows('php-targeted'));
        self::assertSame($registry->names(), CheckProfileAllowlist::fromConfiguredValues()->profiles());
    }

    public function test_an_unknown_profile_name_cannot_be_resolved(): void
    {
        $registry = CheckProfileRegistry::fromConfiguredValues();
        self::assertFalse($registry->has('rm-rf'));

        try {
            $registry->get('rm-rf');
            self::fail('An unknown profile was resolved.');
        } catch (CheckProfileConfigurationException $exception) {
            self::assertSame('check_profile_unknown', $exception->reason);
        }
    }

    /** The four result states stay four and never collapse into each other. */
    public function test_the_result_states_are_four_distinct_values(): void
    {
        self::assertSame(
            ['succeeded', 'failed', 'timed_out', 'tool_unavailable'],
            array_map(static fn (CheckResultState $state): string => $state->value, CheckResultState::cases()),
        );
    }

    /** The execution key separates every one of its four coordinates. */
    public function test_the_execution_key_separates_run_phase_profile_and_tree(): void
    {
        $run = '2f1d4a3c-0000-4000-8000-000000000001';
        $tree = str_repeat('a', 64);
        $key = CheckResult::key($run, CheckPhase::BEFORE_REVIEW, 'php-targeted', $tree);

        self::assertSame($key, CheckResult::key($run, CheckPhase::BEFORE_REVIEW, 'php-targeted', $tree));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $key);
        self::assertNotSame($key, CheckResult::key($run, CheckPhase::FINAL, 'php-targeted', $tree));
        self::assertNotSame($key, CheckResult::key($run, CheckPhase::BEFORE_REVIEW, 'php-all', $tree));
        self::assertNotSame($key, CheckResult::key($run, CheckPhase::BEFORE_REVIEW, 'php-targeted', str_repeat('b', 64)));
        self::assertNotSame($key, CheckResult::key('2f1d4a3c-0000-4000-8000-000000000002', CheckPhase::BEFORE_REVIEW, 'php-targeted', $tree));
    }
}
