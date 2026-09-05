<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentScenario;
use App\AI6\Runs\Console\FakeAgentReleaseGateCommand;
use App\AI6\Runs\WaitReason;
use App\AI6\Runs\WaitReasonRegistry;
use Tests\Feature\Git\SecurityReviewCandidateIsolationTest;
use Tests\Feature\HumanLoop\HumanRequestAnswerTest;
use Tests\TestCase;

final class FakeAgentReleaseGateContractTest extends TestCase
{
    // This is a method inventory, not a proof that a criterion is complete.
    // The command separately reports and blocks known coverage gaps.
    private const SCENARIO_BINDINGS = [
        'AC-01' => [['Tests\\Feature\\Runs\\ReviewOnlyRunContractTest', 'test_manual_completion_binds_status_sync_before_any_lock_release']],
        'AC-03' => [
            ['Tests\\Feature\\Runs\\ExecutionJobContractTest', 'test_a_duplicate_delivery_produces_no_second_preparation_or_effect'],
            [HumanRequestAnswerTest::class, 'test_two_authenticated_browser_sessions_create_one_effect_and_name_the_stale_version'],
            ['Tests\\Feature\\Git\\TicketMutationExecutorTest', 'test_worker_publishes_one_file_commit_and_finalizes_every_bound_projection'],
        ],
        'AC-04' => [
            ['Tests\\Unit\\Git\\GitHardeningContractTest', 'test_the_environment_is_isolated_and_external_helpers_are_disabled'],
            ['Tests\\Feature\\Git\\HardenedGitRunnerTest', 'test_checkout_does_not_run_hooks_filters_textconv_pager_fsmonitor_credentials_or_submodules'],
            ['Tests\\Feature\\Git\\ImplementationImportIsolationTest', 'test_the_agent_view_carries_no_git_metadata_hook_or_host_instruction'],
            [SecurityReviewCandidateIsolationTest::class, 'test_candidate_export_has_no_git_metadata_hooks_or_writable_ref_and_index_paths'],
            ['Tests\\Feature\\Checks\\CheckIsolationTest', 'test_the_check_process_reaches_no_git_metadata_of_the_managed_clone'],
            ['Tests\\Unit\\Agents\\ExecutionHomeManagerTest', 'test_isolation_negative_matrix_exposes_only_the_bound_snapshot_and_exported_source'],
        ],
        'AC-05' => [['Tests\\Feature\\Runs\\ContractChangeTest', 'test_the_reset_lineage_binds_the_changed_instruction_to_a_new_approval_run_and_session']],
        'AC-06' => [['Tests\\Feature\\Git\\PublishCandidateTest', 'test_the_final_commit_and_publish_intent_keep_the_exact_candidate_parent_and_remote_binding']],
        'AC-07' => [['Tests\\Unit\\Runs\\CandidateGateTest', 'test_each_candidate_gate_violation_blocks_individually_and_the_shared_review_evidence_is_reused']],
        'AC-08' => [['Tests\\Feature\\Runs\\ImplementationLimitTurnTest', 'test_each_import_limit_imports_at_the_boundary_and_rejects_one_above']],
        'AC-09' => [['Tests\\Feature\\Runs\\ExecutionJobContractTest', 'test_an_expired_lease_is_reclaimed_and_the_stale_owner_cannot_publish']],
        'AC-10' => [['Tests\\Feature\\Git\\ControlOperationCrashInjectionTest', 'test_control_branch_redelivery_is_exactly_once_at_every_phase_boundary']],
        'AC-11' => [
            ['Tests\\Feature\\Agents\\ImplementationInstructionTest', 'test_an_instruction_update_uses_the_structured_channel_and_rejects_later_scope'],
            ['Tests\\Feature\\Runs\\ContractChangeTest', 'test_the_reset_lineage_binds_the_changed_instruction_to_a_new_approval_run_and_session'],
        ],
        'AC-12' => [
            ['Tests\\Feature\\Runs\\ContractChangeTest', 'test_a_requested_change_parks_the_run_and_discards_sessions_without_moving_the_base'],
            ['Tests\\Feature\\Runs\\ContractChangeTest', 'test_the_reset_lineage_binds_the_changed_instruction_to_a_new_approval_run_and_session'],
        ],
        'AC-13' => [[HumanRequestAnswerTest::class, 'test_two_authenticated_browser_sessions_create_one_effect_and_name_the_stale_version']],
        'AC-14' => [[ReviewOnlyExecutionTest::class, 'test_a_complete_review_only_workflow_keeps_reductions_visible_under_every_security_profile']],
        'AC-15' => [[ImplementationTurnTest::class, 'test_success_and_no_change_bind_exact_session_tree_diff_status_and_mail']],
        'AC-16' => [['Tests\\Unit\\Runs\\ReleaseGateCommandTest', 'test_an_empty_phpunit_selection_fails_the_gate']],
        'AC-17' => [[self::class, 'test_the_architecture_guard_detects_injected_authoritative_mutations']],
    ];

    public function test_all_wait_reasons_have_their_real_registered_producer_and_resolution_path(): void
    {
        $registry = $this->app->make(WaitReasonRegistry::class);
        $expected = array_map(static fn (WaitReason $reason): string => $reason->value, WaitReason::cases());
        $registered = $registry->registeredReasons();
        sort($expected);
        sort($registered);

        self::assertCount(15, $expected);
        self::assertSame($expected, $registered);

        $contracts = [
            WaitReason::HUMAN_QUESTION->value => ['producer' => 'needs_human', 'resolvers' => ['bound_answer'], 'cancellable' => true],
            WaitReason::RESOURCE_LIMIT->value => ['producer' => 'RunLimitPolicy', 'resolvers' => ['reduce', 'increase'], 'cancellable' => true],
            WaitReason::SCOPE_APPROVAL->value => ['producer' => 'ScopeApprovalService', 'resolvers' => ['approve', 'reject'], 'cancellable' => true],
            WaitReason::CONTRACT_CHANGE->value => ['producer' => 'ContractChangeService', 'resolvers' => ['amendment_cas', 'return_to_todo'], 'cancellable' => true],
            WaitReason::CHECK_FAILURE->value => ['producer' => 'RunCheckStep', 'resolvers' => ['retry_unchanged_tree', 'orchestrator_code_fix'], 'cancellable' => true],
            WaitReason::REVIEW_LIMIT->value => ['producer' => 'RunLimitPolicy', 'resolvers' => ['additional_round', 'switch_reviewer', 'finding_disposition'], 'cancellable' => true],
            WaitReason::PROVIDER_ERROR->value => ['producer' => 'AgentAdapter', 'resolvers' => ['retry', 'switch_profile'], 'cancellable' => true],
            WaitReason::INVALID_JSON->value => ['producer' => 'AgentResultValidator', 'resolvers' => ['new_turn', 'switch_profile'], 'cancellable' => true],
            WaitReason::GIT_BASE_CHANGED->value => ['producer' => 'DriftDetector', 'resolvers' => ['controlled_abort'], 'cancellable' => true],
            WaitReason::GIT_CONFLICT->value => ['producer' => 'ControlOperation', 'resolvers' => ['refresh_expected_oid'], 'cancellable' => true],
            WaitReason::MANUAL_GATE->value => ['producer' => 'RunFinalizationStep', 'resolvers' => ['authorize_gate_evidence'], 'cancellable' => true],
            WaitReason::MANUAL_REPORT->value => ['producer' => 'ReportOnlyCompletionService', 'resolvers' => ['confirm_report'], 'cancellable' => true],
            WaitReason::STATUS_SYNC->value => ['producer' => 'CompletionStatusSaga', 'resolvers' => ['refresh_expected_oid'], 'cancellable' => true],
            WaitReason::MANUAL_PUSH->value => ['producer' => 'PublishCompletionService', 'resolvers' => ['authorize_push'], 'cancellable' => true],
            WaitReason::SECURITY_GATE->value => ['producer' => 'SecurityReviewStep', 'resolvers' => ['bound_clear', 'step_up_override'], 'cancellable' => true],
        ];

        foreach (WaitReason::cases() as $reason) {
            $registration = $registry->registration($reason);
            self::assertNotNull($registration, $reason->value);
            self::assertSame($contracts[$reason->value], $registration, $reason->value);
        }

        self::assertNotContains('retry', $registry->registration(WaitReason::MANUAL_GATE)['resolvers']);
        self::assertNotContains('authorize_push', $registry->registration(WaitReason::SECURITY_GATE)['resolvers']);
        self::assertNotContains('bound_answer', $registry->registration(WaitReason::STATUS_SYNC)['resolvers']);

        foreach ([
            'tests/Feature/Runs/WaitResolverMatrixTest.php',
            'tests/Feature/Runs/ReviewOnlyRunContractTest.php',
            'tests/Feature/Runs/HumanRequestResumeTest.php',
        ] as $scenarioPath) {
            self::assertContains($scenarioPath, FakeAgentReleaseGateCommand::TEST_PATHS);
            self::assertFileExists(base_path($scenarioPath));
        }
    }

    public function test_every_automated_ac_has_a_scenario_binding_or_an_explicit_coverage_gap(): void
    {
        $criteria = array_unique([...array_keys(self::SCENARIO_BINDINGS), ...array_keys(FakeAgentReleaseGateCommand::AC_COVERAGE_GAPS)]);
        sort($criteria);
        self::assertSame(array_map(
            static fn (int $number): string => sprintf('AC-%02d', $number),
            range(1, 17),
        ), $criteria);
        self::assertArrayNotHasKey('AC-02', self::SCENARIO_BINDINGS);
        self::assertArrayHasKey('AC-02', FakeAgentReleaseGateCommand::AC_COVERAGE_GAPS);
        self::assertSame(FakeAgentReleaseGateCommand::TEST_PATHS, array_values(array_unique(FakeAgentReleaseGateCommand::TEST_PATHS)));

        foreach (self::SCENARIO_BINDINGS as $criterion => $methods) {
            foreach ($methods as [$class, $method]) {
                self::assertBoundScenarioMethod($class, $method, $criterion);
            }
        }
    }

    public function test_cross_suite_acceptance_criteria_bind_every_required_scenario_file(): void
    {
        $evidence = [
            'AC-01 manual_report producer and resolver' => [
                ['Tests\\Feature\\Runs\\ReviewOnlyRunContractTest', 'test_manual_completion_binds_status_sync_before_any_lock_release'],
                [ReviewOnlyExecutionTest::class, 'test_a_review_only_run_prepares_checks_reviews_and_reports_on_one_bound_checkpoint'],
            ],
            'AC-03 duplicate delivery, browser answer and push retry' => [
                ['Tests\\Feature\\Runs\\ExecutionJobContractTest', 'test_a_duplicate_delivery_produces_no_second_preparation_or_effect'],
                [HumanRequestAnswerTest::class, 'test_two_authenticated_browser_sessions_create_one_effect_and_name_the_stale_version'],
                ['Tests\\Feature\\Git\\TicketMutationExecutorTest', 'test_worker_publishes_one_file_commit_and_finalizes_every_bound_projection'],
            ],
            'AC-04 agent, reviewer and checker isolation' => [
                ['Tests\\Feature\\Git\\ImplementationImportIsolationTest', 'test_the_agent_view_carries_no_git_metadata_hook_or_host_instruction'],
                [SecurityReviewCandidateIsolationTest::class, 'test_candidate_export_has_no_git_metadata_hooks_or_writable_ref_and_index_paths'],
                ['Tests\\Feature\\Checks\\CheckIsolationTest', 'test_the_check_process_reaches_no_git_metadata_of_the_managed_clone'],
                ['Tests\\Unit\\Agents\\ExecutionHomeManagerTest', 'test_isolation_negative_matrix_exposes_only_the_bound_snapshot_and_exported_source'],
            ],
            'AC-08 scope server maximum' => [
                ['Tests\\Feature\\HumanLoop\\ScopeApprovalTest', 'test_an_increase_above_the_server_maximum_is_rejected_without_effect'],
            ],
            'AC-10 control, push/status and lock recovery boundaries' => [
                ['Tests\\Feature\\Git\\ControlOperationCrashInjectionTest', 'test_control_branch_redelivery_is_exactly_once_at_every_phase_boundary'],
                ['Tests\\Feature\\Git\\ControlOperationReconcilerTest', 'test_terminal_operation_with_a_crash_left_project_lease_is_released'],
                ['Tests\\Feature\\Git\\TicketMutationExecutorTest', 'test_worker_publishes_one_file_commit_and_finalizes_every_bound_projection'],
            ],
            'AC-11 structured instruction channel' => [
                ['Tests\\Feature\\Agents\\ImplementationInstructionTest', 'test_an_instruction_update_uses_the_structured_channel_and_rejects_later_scope'],
                ['Tests\\Unit\\Agents\\ExecutionHomeManagerTest', 'test_instruction_patch_is_single_target_and_worker_only'],
            ],
            'AI6-031 retention scope named by the ticket' => [
                ['Tests\\Feature\\Runs\\RunRetentionSweepTest', 'test_the_retention_run_removes_expired_raw_data_with_its_storage_objects_and_repeats_without_effect'],
                ['Tests\\Feature\\Runs\\RunRetentionQueueRedeliveryTest', 'test_a_redelivered_implementation_job_does_not_resurrect_removed_provider_output'],
            ],
        ];

        foreach ($evidence as $criterion => $methods) {
            foreach ($methods as [$class, $method]) {
                self::assertBoundScenarioMethod($class, $method, $criterion);
            }
        }
    }

    public function test_the_release_suite_stays_inside_the_approved_test_scope(): void
    {
        $approved = [
            'tests/Feature/Runs/',
            'tests/Feature/Reviews/',
            'tests/Feature/HumanLoop/',
            'tests/Feature/Git/',
            'tests/Feature/Checks/',
            'tests/Feature/Agents/',
            'tests/Feature/Shared/Security/',
            'tests/Feature/Projects/',
            'tests/Unit/Runs/',
            'tests/Unit/Agents/',
            'tests/Unit/Git/',
        ];

        foreach (FakeAgentReleaseGateCommand::TEST_PATHS as $path) {
            self::assertTrue(array_any(
                $approved,
                static fn (string $prefix): bool => str_starts_with($path, $prefix),
            ), $path);
        }
    }

    /**
     * The third column names the direct writes AC-02 permits for a scenario:
     * an effect with no public producer inside the harness. A new, unnamed one
     * therefore fails here instead of passing unseen.
     */
    public function test_selected_method_bodies_have_no_direct_authoritative_writes(): void
    {
        $scenarios = [
            [self::class, 'test_cross_suite_acceptance_criteria_bind_every_required_scenario_file', []],
            ['Tests\\Feature\\Git\\ImplementationImportIsolationTest', 'test_the_agent_view_carries_no_git_metadata_hook_or_host_instruction', []],
            [SecurityReviewCandidateIsolationTest::class, 'test_candidate_export_has_no_git_metadata_hooks_or_writable_ref_and_index_paths', []],
            [HumanRequestAnswerTest::class, 'test_two_authenticated_browser_sessions_create_one_effect_and_name_the_stale_version', []],
            [ReviewOnlyExecutionTest::class, 'test_a_complete_review_only_workflow_keeps_reductions_visible_under_every_security_profile', []],
            [ImplementationTurnTest::class, 'test_success_and_no_change_bind_exact_session_tree_diff_status_and_mail', []],
            // The stale ticket projection is replaced the way a control-branch
            // refresh replaces it; approval, run and sessions of that scenario
            // come exclusively from their real services.
            ['Tests\\Feature\\Runs\\ContractChangeTest', 'test_the_reset_lineage_binds_the_changed_instruction_to_a_new_approval_run_and_session', ['direct_ticket_projection_write']],
        ];

        foreach ($scenarios as [$class, $method, $permitted]) {
            self::assertSame($permitted, self::directAuthoritativeMutations(self::methodSource($class, $method)), $class.'::'.$method);
        }
    }

    public function test_the_architecture_guard_detects_injected_authoritative_mutations(): void
    {
        $injected = <<<'PHP'
DB::table('runs')->update(['state' => 'done']);
Run::query()->whereKey('run')->update(['state' => 'done']);
TicketReadModel::query()->whereKey('read-model')->delete();
$approval->forceFill(['saga_phase' => 'complete'])->save();
TicketStatus::DONE;
PHP;

        self::assertSame([
            'direct_db_write',
            'direct_run_or_approval_write',
            'direct_ticket_projection_write',
            'direct_model_mutator',
            'direct_ticket_status_reference',
        ], self::directAuthoritativeMutations($injected));
    }

    public function test_every_bound_class_and_its_inherited_fixture_writes_have_an_exact_audit_entry(): void
    {
        $files = ReleaseGateWriteAudit::sourceFiles();
        self::assertSame([], array_values(array_diff(FakeAgentReleaseGateCommand::TEST_PATHS, $files)));
        self::assertContains('tests/Feature/Runs/BuildsFinalizedRunFixture.php', $files);
        self::assertContains('tests/Feature/Runs/BuildsImplementationTurnFixture.php', $files);
        self::assertContains('tests/Feature/Tickets/TicketUiTestCase.php', $files);
        $audit = json_decode((string) file_get_contents(base_path('tests/Fixtures/Agents/release-gate-write-audit.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($audit);
        $inventory = ReleaseGateWriteAudit::inventory();
        self::assertSame(array_keys($audit), array_keys($inventory), 'A new or removed write requires a fresh review, including fixture helpers.');
        $open = [];
        foreach ($inventory as $id => $entry) {
            self::assertSame($audit[$id]['source'], $entry, $id.': the exact write or its method context changed.');
            self::assertContains($audit[$id]['decision'], ['write_under_test', 'external_effect', 'not_authoritative', 'requires_service']);
            self::assertNotSame('', trim($audit[$id]['reason']), $id);
            if ($audit[$id]['decision'] === 'requires_service') {
                $open[] = $id;
            }
        }
        if ($open !== []) {
            self::assertArrayHasKey('AC-02', FakeAgentReleaseGateCommand::AC_COVERAGE_GAPS, implode("\n", $open));
        }
    }

    public function test_the_full_source_audit_distinguishes_executable_writes_from_fixture_strings_and_reads(): void
    {
        $source = <<<'PHP'
<?php
use Illuminate\Support\Facades\DB as Database;
use App\AI6\Runs\Models\Run as Execution;
class Scenario {
    public function scenario() { $this->fixture(); }
    private function fixture() {
        Database::table('runs')->where('id', 'run')->update(['state' => 'done']);
        Database::table('ticket_approvals')->insert(['id' => 'approval']);
        Execution::query()->whereKey('run')->delete();
        $run->forceFill(['state' => 'done'])->save();
        $query->insert(['state' => 'done']);
        Database::statement('UPDATE human_requests SET resolution_state = 1');
        Database::table('runs')->where('id', 'run')->value('state');
        $unpersisted->forceFill(['state' => 'done']);
        $text = "DB::table('runs')->delete()";
    }
}
PHP;
        $entries = ReleaseGateWriteAudit::scan($source);
        self::assertSame($entries, ReleaseGateWriteAudit::scan(str_replace("\n", "\r\n", $source)));
        self::assertSame(['fixture#1', 'fixture#2', 'fixture#3', 'fixture#4', 'fixture#5', 'fixture#6'], array_keys($entries));
        self::assertSame(['runs', 'ticket_approvals', 'Run', 'instance_receiver_requires_review', 'instance_receiver_requires_review', 'human_requests'], array_column($entries, 'target'));
        $changed = ReleaseGateWriteAudit::scan(str_replace("['state' => 'done']", "['state' => 'cancelled']", $source));
        self::assertNotSame($entries['fixture#1']['context_sha256'], $changed['fixture#1']['context_sha256']);
    }

    public function test_the_deterministic_fake_scenario_set_remains_closed_and_complete(): void
    {
        self::assertSame([
            'success',
            'no_change_required',
            'no_change_with_diff',
            'human_request',
            'findings',
            'invalid_json',
            'provider_error',
            'security_findings',
            'security_inconclusive',
            'untrusted_evidence',
            'invalid_criterion_reference',
            'regression_after_fix',
            'rejects_finding',
            'verification_inconclusive',
            'verification_invalid_schema',
        ], array_map(static fn (AgentScenario $scenario): string => $scenario->value, AgentScenario::cases()));
    }

    private static function methodSource(string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    private static function assertBoundScenarioMethod(string $class, string $method, string $criterion): void
    {
        self::assertTrue(method_exists($class, $method), $criterion.': '.$class.'::'.$method);
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        self::assertIsString($file, $criterion);
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $file);
        self::assertStringStartsWith($base, $normalized, $criterion);
        self::assertContains(substr($normalized, strlen($base)), FakeAgentReleaseGateCommand::TEST_PATHS, $criterion);
    }

    /** @return list<string> */
    private static function directAuthoritativeMutations(string $source): array
    {
        $findings = [];
        $patterns = [
            'direct_db_write' => '/DB::table\([^)]*\)[\s\S]{0,240}?->(?:insert|update|delete)\s*\(/',
            'direct_run_or_approval_write' => '/(?:Run|TicketApproval)::query\(\)[\s\S]{0,240}?->(?:create|update|delete)\s*\(/',
            'direct_ticket_projection_write' => '/TicketReadModel::query\(\)[\s\S]{0,240}?->(?:create|update|delete)\s*\(/',
            'direct_model_mutator' => '/->(?:save|update|forceFill)\s*\(/',
            'direct_ticket_status_reference' => '/\bTicketStatus::/',
        ];
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $findings[] = $name;
            }
        }

        return $findings;
    }
}
