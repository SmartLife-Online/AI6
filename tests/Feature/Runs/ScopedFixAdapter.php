<?php

namespace Tests\Feature\Runs;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentResultContext;

/**
 * A deterministic implementation-role double that writes exactly the given paths.
 *
 * The fix turn consumes the same implementation contract as the first turn, so the
 * double only has to report the paths it wrote; `finding_statuses` stay optional.
 */
final readonly class ScopedFixAdapter implements AgentAdapter
{
    /**
     * @param  array<string, string>  $writes  path => content
     * @param  list<string>  $changedPaths
     */
    public function __construct(private array $writes, private array $changedPaths) {}

    public function result(AgentResultContext $context): string
    {
        return json_encode([
            'schema_version' => 'ai6.agent.v1',
            'status' => 'completed',
            'summary' => 'Deterministisches Scope-Testergebnis des Fixturns.',
            'prompt_snapshot_hash' => $context->promptSnapshot->hash,
            'instruction_snapshot_hash' => $context->instructionSnapshot->hash,
            'provider_runtime_profile_hash' => $context->runtimeProfile->hash,
            'decisions' => [['key' => 'd1', 'title' => 'Fix', 'rationale' => 'Deterministische Entscheidung.']],
            'changed_paths' => $this->changedPaths,
            'open_manual_gates' => [],
            'implementation_summary' => [
                'changed_components' => ['Example'],
                'decisions' => ['Deterministische Entscheidung.'],
                'assumptions' => [],
                'deviations' => [],
                'known_limits' => [],
                'tests' => [],
                'review_focus' => $this->changedPaths,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param list<string> $unreachablePaths */
    public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
    {
        foreach ($this->writes as $path => $content) {
            $target = rtrim($isolatedTree, '/\\').'/'.$path;
            $directory = dirname($target);
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($target, $content);
        }

        return $this->result($context);
    }
}
