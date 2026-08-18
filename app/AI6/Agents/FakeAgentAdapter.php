<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Redaction\RedactionContext;
use JsonException;
use RuntimeException;

final class FakeAgentAdapter implements AgentAdapter
{
    public string $lastRenderedImplementationPrompt = '';

    /** @var array<string, string> */
    public array $lastAccessProbes = [];

    public int $turnCount = 0;

    public function __construct(
        private readonly AgentScenario $scenario = AgentScenario::SUCCESS,
        private readonly ?ControlProcessRunner $processes = null,
    ) {}

    public function result(AgentResultContext $context): string
    {
        $scenario = $this->scenario;
        if ($scenario === AgentScenario::INVALID_JSON) {
            return '{"schema_version":';
        }

        $document = $this->baseDocument($context, $this->status($scenario, $context));
        if (in_array($scenario, [AgentScenario::FINDINGS, AgentScenario::SECURITY_FINDINGS], true)) {
            $document['findings'] = [$this->finding($context)];
            $document['criterion_coverage'] = $this->coverage($context);
        }
        if ($scenario === AgentScenario::HUMAN_REQUEST) {
            $document['human_request'] = [
                'kind' => 'clarification',
                'title' => 'Rückfrage',
                'message' => 'Eine Entscheidung wird benötigt.',
                'why_needed' => 'Die gebundene Umsetzung benötigt eine Auswahl.',
                'response_mode' => 'select',
                'options' => [['key' => 'a', 'label' => 'Option A'], ['key' => 'b', 'label' => 'Option B']],
                'recommended_option' => 'a',
                'affected_paths' => ['app/Example.php'],
                'criterion_refs' => $context->criterionRefs,
            ];
        }
        if (in_array($context->role, [AgentRole::QUALITY_REVIEW, AgentRole::SECURITY_REVIEW], true)
            && ! array_key_exists('criterion_coverage', $document)) {
            $document['criterion_coverage'] = $this->coverage($context);
        }

        try {
            return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new \LogicException('The deterministic fake document could not be encoded.');
        }
    }

    /**
     * @param  list<string>  $unreachablePaths
     */
    public function turn(AgentResultContext $context, string $isolatedTree, array $unreachablePaths = []): string
    {
        $this->turnCount++;
        $this->lastRenderedImplementationPrompt = $context->promptSnapshot->renderedPrompts['implementation'] ?? '';
        $document = $this->result($context);
        $tree = $this->regularDirectory($isolatedTree);
        $io = $tree.'-io';
        if (! is_dir($io) && ! mkdir($io, 0700, true) && ! is_dir($io)) {
            throw new RuntimeException('The isolated fake-agent turn staging directory is unavailable.');
        }
        $requestPath = $io.DIRECTORY_SEPARATOR.'request.json';
        $resultPath = $io.DIRECTORY_SEPARATOR.'result.json';
        $scriptPath = $io.DIRECTORY_SEPARATOR.'turn.php';
        $request = json_encode([
            'write_example' => in_array($this->scenario, [AgentScenario::SUCCESS, AgentScenario::NO_CHANGE_WITH_DIFF], true),
            'example_contents' => "<?php\n\n// fake-agent-change\n",
            'document' => $document,
            'env_probes' => ['APP_KEY', 'MAIL_PASSWORD', 'AI6_GIT_SSH_KEY', 'DB_DATABASE'],
            'path_probes' => $unreachablePaths,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($requestPath, $request, LOCK_EX) !== strlen($request)
            || file_put_contents($scriptPath, $this->childScript(), LOCK_EX) === false) {
            throw new RuntimeException('The isolated fake-agent turn request could not be staged.');
        }

        $parent = dirname($tree);
        $basedir = rtrim($parent, '/\\').DIRECTORY_SEPARATOR;
        $runner = $this->processes ?? app(ControlProcessRunner::class);
        $result = $runner->run(new ProcessRequest(
            [PHP_BINARY, '-d', 'open_basedir='.$basedir, $scriptPath, $requestPath, $tree, $resultPath],
            $tree,
            ['AI6_RUNTIME_PROFILE'],
            ['AI6_RUNTIME_PROFILE' => $context->runtimeProfile->id],
            new RedactionContext('turn', 'turn', 'fake-agent-turn'),
            policy: ProcessPolicyName::AGENT,
            resultDirectory: $io,
            artifactDirectory: $io,
        ));
        if (! $result->succeeded()) {
            throw new RuntimeException('The isolated fake-agent turn failed: '.$result->errorOutput);
        }
        $payloadBytes = is_file($resultPath) ? file_get_contents($resultPath) : false;
        if (! is_string($payloadBytes)) {
            throw new RuntimeException('The isolated fake-agent turn produced no result file.');
        }
        $payload = json_decode($payloadBytes, true, 8, JSON_THROW_ON_ERROR);
        $this->lastAccessProbes = is_array($payload['probes'] ?? null) ? $payload['probes'] : [];
        foreach ($this->lastAccessProbes as $key => $status) {
            if (str_starts_with((string) $key, 'path:') && $status === 'readable') {
                throw new RuntimeException('The isolated turn reached a forbidden path.');
            }
        }

        return is_string($payload['result'] ?? null) ? $payload['result'] : $document;
    }

    /** @return array<string, mixed> */
    private function baseDocument(AgentResultContext $context, AgentResultStatus $status): array
    {
        $document = [
            'schema_version' => $context->role === AgentRole::IMPLEMENTATION ? 'ai6.agent.v1' : 'ai6.quality-review.v1',
            'status' => $status->value,
            'summary' => 'Deterministisches Fake-Ergebnis.',
            'prompt_snapshot_hash' => $context->promptSnapshot->hash,
            'instruction_snapshot_hash' => $context->instructionSnapshot->hash,
            'provider_runtime_profile_hash' => $context->runtimeProfile->hash,
        ];
        if ($context->role === AgentRole::IMPLEMENTATION) {
            $reportsChange = in_array($this->scenario, [AgentScenario::SUCCESS, AgentScenario::NO_CHANGE_WITH_DIFF], true);
            $document['decisions'] = [['key' => 'd1', 'title' => 'Umsetzung', 'rationale' => 'Deterministische Entscheidung.']];
            $document['changed_paths'] = $reportsChange ? ['app/Example.php'] : [];
            $document['open_manual_gates'] = [];
            $document['implementation_summary'] = [
                'changed_components' => $reportsChange ? ['Example'] : [],
                'decisions' => ['Deterministische Entscheidung.'],
                'assumptions' => [],
                'deviations' => [],
                'known_limits' => [],
                'tests' => [],
                'review_focus' => $reportsChange ? ['app/Example.php'] : [],
            ];
        }

        return $document;
    }

    private function status(AgentScenario $scenario, AgentResultContext $context): AgentResultStatus
    {
        return match ($scenario) {
            AgentScenario::SUCCESS => match ($context->role) {
                AgentRole::IMPLEMENTATION => AgentResultStatus::COMPLETED,
                AgentRole::QUALITY_REVIEW => AgentResultStatus::NOTHING_TO_FIX,
                AgentRole::FINDING_VERIFICATION, AgentRole::SECURITY_REVIEW => AgentResultStatus::CLEAR,
            },
            AgentScenario::NO_CHANGE_REQUIRED, AgentScenario::NO_CHANGE_WITH_DIFF => AgentResultStatus::NO_CHANGE_REQUIRED,
            AgentScenario::HUMAN_REQUEST => AgentResultStatus::NEEDS_HUMAN,
            AgentScenario::FINDINGS => AgentResultStatus::FINDINGS_TO_FIX,
            AgentScenario::PROVIDER_ERROR => AgentResultStatus::FAILED,
            AgentScenario::SECURITY_FINDINGS => AgentResultStatus::SECURITY_FINDINGS,
            AgentScenario::INVALID_JSON => throw new \LogicException('Invalid JSON has no typed status.'),
        };
    }

    /** @return array<string, mixed> */
    private function finding(AgentResultContext $context): array
    {
        return [
            'local_id' => 'finding-1',
            'severity' => 'must_fix',
            'disposition' => 'open',
            'category' => 'contract',
            'file' => 'app/Example.php',
            'line' => 1,
            'title' => 'Deterministischer Befund',
            'evidence' => 'Das erwartete Ergebnis fehlt.',
            'expected_result' => 'Der Vertrag ist erfüllt.',
            'criterion_refs' => $context->criterionRefs,
        ];
    }

    /** @return list<array{criterion_id: string, status: string, evidence: string}> */
    private function coverage(AgentResultContext $context): array
    {
        return array_map(
            static fn (string $criterion): array => ['criterion_id' => $criterion, 'status' => 'satisfied', 'evidence' => 'Deterministischer Nachweis.'],
            $context->criterionRefs,
        );
    }

    private function regularDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || is_link($path) || ! is_dir($path)) {
            throw new RuntimeException('The isolated fake-agent tree is unavailable.');
        }

        return $real;
    }

    private function childScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
$request = json_decode((string) file_get_contents($argv[1]), true);
$tree = $argv[2];
$out = $argv[3];
$probes = [];
foreach ($request['env_probes'] ?? [] as $name) {
    $probes['env:'.$name] = getenv($name) === false ? 'missing' : 'present';
}
foreach ($request['path_probes'] ?? [] as $path) {
    $readable = false;
    if ((@is_file($path) || @is_dir($path)) && @is_readable($path)) {
        $bytes = @file_get_contents($path);
        $readable = $bytes !== false || @is_dir($path);
    }
    $probes['path:'.$path] = $readable ? 'readable' : 'denied';
}
if (($request['write_example'] ?? false) === true) {
    $target = rtrim(str_replace('\\', '/', $tree), '/').'/app/Example.php';
    $directory = dirname($target);
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($target, (string) $request['example_contents']);
}
file_put_contents($out, json_encode([
    'result' => (string) $request['document'],
    'probes' => $probes,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
PHP;
    }
}
