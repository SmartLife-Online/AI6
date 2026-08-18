<?php

namespace Tests\Unit\Runs;

use App\AI6\Git\RunPatchChange;
use App\AI6\Git\RunPatchStatus;
use App\AI6\Runs\ImportLimit;
use App\AI6\Runs\ImportLimitResult;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunLimitPolicy;
use Tests\TestCase;

final class RunLimitPolicyTest extends TestCase
{
    /** TC-07 */
    public function test_each_import_limit_accepts_the_exact_maximum_and_rejects_one_above(): void
    {
        $policy = $this->app->make(RunLimitPolicy::class);
        $run = new Run;
        $run->forceFill([
            'id' => '11111111-1111-1111-1111-111111111111',
            'agent_profile_snapshot' => ['limits' => [
                'max_changed_files' => 2,
                'max_changed_bytes' => 20,
                'max_artifacts' => 2,
                'max_artifact_bytes' => 20,
                'max_total_artifact_bytes' => 30,
                'max_provider_output_bytes' => 20,
            ]],
        ]);

        $atLimit = [
            new RunPatchChange('app/A.php', RunPatchStatus::ADDED, 10),
            new RunPatchChange('app/B.php', RunPatchStatus::ADDED, 10),
        ];
        self::assertNull($policy->evaluate($run, $atLimit, [['bytes' => 15], ['bytes' => 15]], 20));

        $this->assertLimit($policy->evaluate($run, [
            ...$atLimit,
            new RunPatchChange('app/C.php', RunPatchStatus::ADDED, 1),
        ], [['bytes' => 1]], 1), ImportLimit::MAX_CHANGED_FILES);
        $this->assertLimit($policy->evaluate($run, [
            new RunPatchChange('app/A.php', RunPatchStatus::ADDED, 21),
        ], [['bytes' => 1]], 1), ImportLimit::MAX_CHANGED_BYTES);
        $this->assertLimit($policy->evaluate($run, $atLimit, [['bytes' => 1], ['bytes' => 1], ['bytes' => 1]], 1), ImportLimit::MAX_ARTIFACTS);
        $this->assertLimit($policy->evaluate($run, $atLimit, [['bytes' => 21]], 1), ImportLimit::MAX_ARTIFACT_BYTES);
        $this->assertLimit($policy->evaluate($run, $atLimit, [['bytes' => 16], ['bytes' => 15]], 1), ImportLimit::MAX_TOTAL_ARTIFACT_BYTES);
        $this->assertLimit($policy->evaluate($run, $atLimit, [['bytes' => 1]], 21), ImportLimit::MAX_PROVIDER_OUTPUT_BYTES);
    }

    private function assertLimit(?ImportLimitResult $result, ImportLimit $expected): void
    {
        self::assertInstanceOf(ImportLimitResult::class, $result);
        self::assertSame($expected, $result->limit);
    }

    public function test_a_project_value_cannot_exceed_the_server_maximum(): void
    {
        $policy = $this->app->make(RunLimitPolicy::class);
        $run = new Run;
        $run->forceFill([
            'id' => '11111111-1111-1111-1111-111111111111',
            'agent_profile_snapshot' => ['limits' => [
                'max_changed_files' => 999999,
                'max_changed_bytes' => 999999999,
                'max_artifacts' => 999999,
                'max_artifact_bytes' => 999999999,
                'max_total_artifact_bytes' => 999999999,
                'max_provider_output_bytes' => 999999999,
            ]],
        ]);
        $effective = $policy->effective($run);
        $maxima = config('ai6.project_config.server_maxima');
        self::assertIsArray($maxima);
        foreach ($effective as $name => $value) {
            self::assertLessThanOrEqual($maxima[$name], $value, $name);
        }
    }
}
