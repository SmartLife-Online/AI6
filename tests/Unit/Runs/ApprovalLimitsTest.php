<?php

namespace Tests\Unit\Runs;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalStartEligibility;
use InvalidArgumentException;
use Tests\TestCase;

final class ApprovalLimitsTest extends TestCase
{
    public function test_all_effective_limits_are_persisted_and_server_maxima_are_closed(): void
    {
        $requested = config('ai6.project_config.server_defaults.limits');
        $limits = ApprovalLimits::fromConfiguredValues($requested, $this->app->make(AgentInputLimits::class))->jsonSerialize();

        self::assertSame(17, count($limits));
        self::assertSame(12, $limits['max_added_scope_paths']);
        self::assertSame(16, $limits['max_instruction_files']);
        self::assertSame(2097152, $limits['max_prompt_input_bytes']);

        $maxima = config('ai6.project_config.server_maxima');
        self::assertIsArray($maxima);
        foreach ($maxima as $name => $maximum) {
            $atMaximum = $maxima;
            self::assertSame(
                $maximum,
                ApprovalLimits::fromConfiguredValues($atMaximum, $this->app->make(AgentInputLimits::class))->values[$name],
            );

            $aboveMaximum = $maxima;
            $aboveMaximum[$name] = $maximum + 1;
            try {
                ApprovalLimits::fromConfiguredValues($aboveMaximum, $this->app->make(AgentInputLimits::class));
                self::fail('Das Projektlimit oberhalb des Servermaximums wurde akzeptiert: '.$name);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($name, $exception->getMessage());
            }
        }

        $projectAttempt = $requested;
        $projectAttempt['max_instruction_files'] = 999999;
        $closed = ApprovalLimits::fromConfiguredValues($projectAttempt, $this->app->make(AgentInputLimits::class));
        self::assertSame(16, $closed->values['max_instruction_files']);
    }

    public function test_dependencies_only_block_start_eligibility_not_queue_state(): void
    {
        $decision = (new ApprovalStartEligibility)->decide([], ['AI6-011' => 'todo'], ['done'], true, false, 'queued');
        self::assertFalse($decision['eligible']);
        self::assertSame(['dependency_unsatisfied:AI6-011'], $decision['reasons']);

        self::assertTrue((new ApprovalStartEligibility)->decide([], ['AI6-011' => 'done'], ['done'], true, false, 'queued')['eligible']);
    }
}
