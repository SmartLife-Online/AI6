<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\CheckpointDiffRecorder;
use App\AI6\Runs\RetentionCategory;
use App\AI6\Runs\RetentionLimit;
use App\AI6\Runs\RetentionPolicy;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TC-10 of AI6-031: every retention value comes from trusted instance
 * configuration and nothing else; a missing or invalid value fails by name.
 */
final class RetentionPolicyTest extends TestCase
{
    public function test_every_category_resolves_its_configured_days_and_bytes(): void
    {
        config([
            'ai6.retention.run_logs' => ['max_days' => '11', 'max_bytes' => '1111'],
            'ai6.retention.agent_raw_output' => ['max_days' => 12, 'max_bytes' => 2222],
            'ai6.retention.check_logs' => ['max_days' => '13', 'max_bytes' => '3333'],
            'ai6.retention.artifacts' => ['max_days' => 14, 'max_bytes' => 4444],
            'ai6.retention.active_run_grace_days' => '3',
        ]);

        $policy = new RetentionPolicy(new StrictPositiveIntegerParser);

        self::assertSame([11, 1111], [$policy->limit(RetentionCategory::RUN_LOGS)->maxDays, $policy->limit(RetentionCategory::RUN_LOGS)->maxBytes]);
        self::assertSame([12, 2222], [$policy->limit(RetentionCategory::AGENT_RAW_OUTPUT)->maxDays, $policy->limit(RetentionCategory::AGENT_RAW_OUTPUT)->maxBytes]);
        self::assertSame([13, 3333], [$policy->limit(RetentionCategory::CHECK_LOGS)->maxDays, $policy->limit(RetentionCategory::CHECK_LOGS)->maxBytes]);
        self::assertSame([14, 4444], [$policy->limit(RetentionCategory::ARTIFACTS)->maxDays, $policy->limit(RetentionCategory::ARTIFACTS)->maxBytes]);
        self::assertSame(3, $policy->activeRunGraceDays);
        self::assertSame(RetentionCategory::AGENT_RAW_OUTPUT, RetentionCategory::forArtifactKind(RunArtifactKind::PROVIDER_RAW));
        foreach ([RunArtifactKind::IMPLEMENTATION_SUMMARY, RunArtifactKind::CONTEXT_PACKAGE, RunArtifactKind::COMPLETION_REPORT, RunArtifactKind::CHECKPOINT_DIFF, RunArtifactKind::QUARANTINED_PATH, RunArtifactKind::LIMIT_GRANT, RunArtifactKind::LIMIT_PENDING] as $kind) {
            self::assertSame(RetentionCategory::ARTIFACTS, RetentionCategory::forArtifactKind($kind), $kind->value);
        }
        self::assertSame(12, $policy->artifactLimit(RunArtifactKind::PROVIDER_RAW)->maxDays);

        $created = CarbonImmutable::parse('2026-09-02 10:00:00');
        self::assertSame('2026-09-16 10:00:00', $policy->artifactLimit(RunArtifactKind::PROVIDER_RAW)->expiresAt($created)->addDays(2)->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-16 10:00:00', $policy->limit(RetentionCategory::ARTIFACTS)->expiresAt($created)->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-19 10:00:00', $policy->purgeDeadline($created->addDays(14), true)->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-16 10:00:00', $policy->purgeDeadline($created->addDays(14), false)->format('Y-m-d H:i:s'));
        self::assertTrue($policy->limit(RetentionCategory::ARTIFACTS)->exceeds(4445));
        self::assertFalse($policy->limit(RetentionCategory::ARTIFACTS)->exceeds(4444));
    }

    public function test_project_configuration_and_provider_output_cannot_set_or_extend_a_value(): void
    {
        config([
            'ai6.retention.artifacts' => ['max_days' => 30, 'max_bytes' => 20000000],
            // A managed project and a provider may carry anything under a
            // "retention" key; the resolver never reads either source.
            'ai6.project_config.server_defaults.retention' => ['artifacts' => ['max_days' => 3650, 'max_bytes' => PHP_INT_MAX]],
            'ai6.project_config.server_defaults.limits.retention_days' => 3650,
        ]);
        // Provider output that asks for a longer retention is proven inert at
        // the storage boundary in RunRetentionSweepTest; here the resolver's
        // only input is the trusted section, and the source proves it reads
        // nothing else.
        $policy = new RetentionPolicy(new StrictPositiveIntegerParser);

        self::assertSame(30, $policy->limit(RetentionCategory::ARTIFACTS)->maxDays);
        self::assertSame(20000000, $policy->limit(RetentionCategory::ARTIFACTS)->maxBytes);
        self::assertSame(7, $policy->activeRunGraceDays);
        $source = file_get_contents(app_path('AI6/Runs/RetentionPolicy.php'));
        self::assertIsString($source);
        $code = preg_replace('~/\*.*?\*/~s', '', $source);
        self::assertIsString($code);
        self::assertSame(1, preg_match_all('/config\(/', $code), 'The resolver reads exactly one configuration section.');
        self::assertStringContainsString("config('ai6.retention')", $source);
        foreach (['project_config', 'config_snapshot', 'scope_snapshot', 'agent_profile_snapshot', 'redacted_metadata', 'AgentResult'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, $forbidden);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'missing category' => [['ai6.retention' => ['run_logs' => ['max_days' => 1, 'max_bytes' => 1]]], 'ai6.retention must contain exactly the categories'];
        yield 'missing bytes' => [['ai6.retention.check_logs' => ['max_days' => 30]], 'ai6.retention.check_logs must contain exactly max_days and max_bytes'];
        yield 'zero days' => [['ai6.retention.artifacts.max_days' => '0'], 'ai6.retention.artifacts.max_days must be a positive integer'];
        yield 'empty bytes' => [['ai6.retention.agent_raw_output.max_bytes' => ''], 'ai6.retention.agent_raw_output.max_bytes must be a positive integer'];
        yield 'textual days' => [['ai6.retention.run_logs.max_days' => 'dreißig'], 'ai6.retention.run_logs.max_days must be a positive integer'];
        yield 'oversized days' => [['ai6.retention.run_logs.max_days' => '99999'], 'ai6.retention.run_logs.max_days must be a positive integer'];
        yield 'grace missing' => [['ai6.retention.active_run_grace_days' => null], 'ai6.retention.active_run_grace_days must be a positive integer'];
    }

    /** The truncation marker is part of a log budget: a limit the marker alone would exceed is refused, every accepted limit holds after the cut. */
    public function test_a_log_size_limit_below_the_truncation_marker_is_refused_and_every_accepted_limit_holds_after_the_cut(): void
    {
        $marker = RetentionLimit::TRUNCATION_MARKER;
        foreach (['run_logs', 'check_logs'] as $category) {
            foreach ([1, strlen($marker) - 1, strlen($marker)] as $tooSmall) {
                config(['ai6.retention.'.$category.'.max_bytes' => $tooSmall]);
                try {
                    new RetentionPolicy(new StrictPositiveIntegerParser);
                    self::fail($category.' must refuse '.$tooSmall.' bytes.');
                } catch (ConfigurationException $exception) {
                    self::assertStringContainsString('ai6.retention.'.$category.'.max_bytes must exceed the length of the truncation marker', $exception->getMessage());
                }
            }
            config(['ai6.retention.'.$category.'.max_bytes' => 65536]);
        }
        // Artifacts are refused above their limit, never cut, so the marker
        // does not bind them; the checkpoint diff header does bind the
        // artifact category, because a budget below it could store no diff.
        self::assertSame(CheckpointDiffRecorder::HEADER_MAX_BYTES, strlen(CheckpointDiffRecorder::header(str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64))), 'The constant is the header over SHA-256 object names.');
        config(['ai6.retention.artifacts.max_bytes' => CheckpointDiffRecorder::HEADER_MAX_BYTES - 1, 'ai6.retention.agent_raw_output.max_bytes' => 8]);
        try {
            new RetentionPolicy(new StrictPositiveIntegerParser);
            self::fail('An artifact budget below the checkpoint diff header must refuse.');
        } catch (ConfigurationException $exception) {
            self::assertSame('Configuration key ai6.retention.artifacts.max_bytes must be at least the checkpoint diff header size ('.CheckpointDiffRecorder::HEADER_MAX_BYTES.' bytes).', $exception->getMessage());
        }
        config(['ai6.retention.artifacts.max_bytes' => CheckpointDiffRecorder::HEADER_MAX_BYTES]);
        $policy = new RetentionPolicy(new StrictPositiveIntegerParser);
        self::assertSame(CheckpointDiffRecorder::HEADER_MAX_BYTES, $policy->limit(RetentionCategory::ARTIFACTS)->maxBytes, 'The header size itself is the smallest accepted artifact budget.');
        self::assertSame(8, $policy->limit(RetentionCategory::AGENT_RAW_OUTPUT)->maxBytes, 'Agent raw output stores no checkpoint diff and knows no minimum.');
        config(['ai6.retention.artifacts.max_bytes' => 20000000, 'ai6.retention.agent_raw_output.max_bytes' => 10000000]);

        // The smallest accepted budget keeps one byte of text plus the marker.
        $smallest = strlen($marker) + 1;
        config(['ai6.retention.run_logs.max_bytes' => $smallest]);
        $limit = (new RetentionPolicy(new StrictPositiveIntegerParser))->limit(RetentionCategory::RUN_LOGS);
        $cut = $limit->truncate('Übergroßer Eintrag '.str_repeat('x', 100));
        self::assertLessThanOrEqual($smallest, strlen($cut));
        self::assertSame($marker, $cut, 'The multibyte first character does not fit into one byte; only the marker remains.');
        self::assertSame('a'.$marker, $limit->truncate('a'.str_repeat('b', 100)));
        self::assertSame(str_repeat('c', $smallest), $limit->truncate(str_repeat('c', $smallest)), 'Text within the budget is untouched.');
        config(['ai6.retention.run_logs.max_bytes' => 40]);
        $limit = (new RetentionPolicy(new StrictPositiveIntegerParser))->limit(RetentionCategory::RUN_LOGS);
        $cut = $limit->truncate(str_repeat('ü', 100));
        self::assertLessThanOrEqual(40, strlen($cut));
        self::assertStringEndsWith($marker, $cut);
        self::assertSame(1, preg_match('//u', $cut), 'The cut never splits a multibyte character.');
        config(['ai6.retention.run_logs.max_bytes' => 65536]);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidConfigurations')]
    public function test_a_missing_or_invalid_value_fails_with_a_named_error(array $overrides, string $expectedMessage): void
    {
        config($overrides);

        try {
            new RetentionPolicy(new StrictPositiveIntegerParser);
            self::fail('The resolution must fail.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($expectedMessage, $exception->getMessage());
            self::assertStringNotContainsString('dreißig', $exception->getMessage(), 'The read value is never echoed.');
            self::assertStringNotContainsString('99999', $exception->getMessage(), 'The read value is never echoed.');
        }
    }
}
