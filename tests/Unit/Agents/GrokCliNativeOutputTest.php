<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentTurnResult;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\InstructionSnapshot;
use ReflectionMethod;
use Tests\TestCase;

final class GrokCliNativeOutputTest extends TestCase
{
    public function test_native_synthetic_pin_output_is_parsed_without_rewriting_events(): void
    {
        // Unmodified synthetic transport output from Linux grok 1.0.5 (5115b46bc9); not sandbox acceptance evidence.
        $output = file_get_contents(base_path('tests/Fixtures/Agents/grok-native-events.ndjson'));
        self::assertIsString($output);
        $home = new ExecutionHome('', '', '/var/lib/ai6-grok-probe/workspace', '', '', '', '', '', '', '');
        $result = (new ReflectionMethod(GrokCliAdapter::class, 'answer'))->invoke(app(GrokCliAdapter::class), $output, $home, 'ai6-probe');
        self::assertInstanceOf(AgentTurnResult::class, $result);
        self::assertSame('{"probe":"complete"}', $result->bytes);
        self::assertSame(24, $result->usage['input_tokens']);
        self::assertSame(2, $result->usage['num_turns']);
        self::assertArrayNotHasKey('cost_usd', $result->usage);
        self::assertArrayNotHasKey('api_duration_ms', $result->usage);
    }

    public function test_native_inspect_paths_pass_the_production_redacted_comparison(): void
    {
        $output = file_get_contents(base_path('tests/Fixtures/Agents/grok-native-inspect.json'));
        self::assertIsString($output);
        $base = '/var/lib/ai6-grok-probe/inputs/execution-native/slot';
        $home = new ExecutionHome('', '', $base.'/workspace', $base.'/home', '', '', '', '', '', '');
        (new ReflectionMethod(GrokCliAdapter::class, 'assertSurface'))->invoke(app(GrokCliAdapter::class), $output, $home,
            new InstructionSnapshot('grok_cli', [], hash('sha256', 'native-inspect')));
        self::assertStringContainsString($base.'/home/config.toml', $output);
    }
}
