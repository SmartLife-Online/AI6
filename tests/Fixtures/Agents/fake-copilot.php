<?php

// Standalone subprocess fixture: no autoloader, database, network or application secrets.
if (! isset($argv)) {
    exit(2);
}
$scenario = $argv[1] ?? 'success';
$arguments = array_slice($argv, 2);
$nativeHome = (string) getenv('COPILOT_HOME');
$temporary = (string) getenv('TMPDIR');
$scratch = dirname($temporary);
if ($arguments === ['--version']) {
    echo $scenario === 'version_drift' ? "GitHub Copilot CLI 1.0.84.\n" : "GitHub Copilot CLI 1.0.83.\n";
    exit(0);
}
if (in_array('plugins', $arguments, true)) {
    $settings = json_decode((string) file_get_contents($nativeHome.'/settings.json'), true, 32, JSON_THROW_ON_ERROR);
    $plugins = [];
    foreach (['customize-cloud-agent', 'discover-resources', 'github-pr-media'] as $skill) {
        $plugins[] = ['kind' => 'skill', 'name' => $skill, 'scope' => 'builtin', 'source' => 'builtin',
            'enabled' => $scenario === 'enabled_skill' || ! in_array($skill, $settings['disabledSkills'] ?? [], true)];
    }
    if ($scenario === 'foreign_extension') {
        $plugins[] = ['kind' => 'mcp', 'name' => 'foreign'];
    }
    echo json_encode(['plugins' => $plugins, 'errors' => []], JSON_THROW_ON_ERROR);
    exit(0);
}
$prompt = stream_get_contents(STDIN);
$env = [];
foreach (['HOME', 'COPILOT_HOME', 'COPILOT_GITHUB_TOKEN', 'TMPDIR', 'APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'GITHUB_TOKEN', 'GH_TOKEN', 'AI6_GIT_SSH_KEY', 'CODEX_HOME', 'XDG_CONFIG_HOME'] as $name) {
    $env[$name] = getenv($name) !== false ? 'present' : 'missing';
}
file_put_contents($scratch.'/observation.json', json_encode(['argv' => $arguments, 'env' => $env,
    'prompt_bytes' => strlen($prompt), 'prompt_sha256' => hash('sha256', $prompt),
    'cwd' => str_replace('\\', '/', (string) getcwd()), 'home' => $nativeHome,
], JSON_THROW_ON_ERROR));
if ($scenario === 'exit_failure') {
    fwrite(STDERR, 'provider failure: permission denied; token=synthetic-diagnostic-secret');
    exit(9);
}
if ($scenario === 'timeout') {
    $deadline = microtime(true) + 120;
    while (microtime(true) < $deadline) {
        file_put_contents($scratch.'/pulse', (string) microtime(true));
        usleep(10000);
    }
}
if ($scenario === 'output_limit') {
    echo str_repeat('x', 20000000);
    exit(0);
}
if ($scenario === 'native_state') {
    @file_put_contents($nativeHome.'/session-state/foreign.json', '{}');
}
$usageIndex = array_search('--usage-output-file', $arguments, true);
if ($usageIndex !== false && $scenario !== 'missing_usage') {
    file_put_contents($arguments[$usageIndex + 1], json_encode($scenario === 'null_usage'
        ? ['totalPremiumRequestCost' => null, 'totalApiDurationMs' => 0]
        : ['totalPremiumRequestCost' => 0, 'totalApiDurationMs' => 12], JSON_THROW_ON_ERROR));
}
$context = json_decode((string) file_get_contents(dirname($nativeHome).'/runtime/turn.json'), true, 64, JSON_THROW_ON_ERROR);
$constant = static function (string $name) use ($prompt): string {
    preg_match('/^'.preg_quote($name, '/').': (.+)$/m', $prompt, $matches);

    return $matches[1] ?? 'unbound';
};
$document = ['schema_version' => $constant('schema_version'), 'status' => 'nothing_to_fix', 'summary' => 'Deterministischer Copilot-Review.',
    'prompt_snapshot_hash' => $constant('prompt_snapshot_hash'), 'instruction_snapshot_hash' => $constant('instruction_snapshot_hash'),
    'provider_runtime_profile_hash' => $constant('provider_runtime_profile_hash'), 'human_request' => null,
    'findings' => [], 'criterion_coverage' => array_map(static fn (string $id): array => ['criterion_id' => $id, 'status' => 'satisfied', 'evidence' => 'Fixture.'], $context['criterion_refs']),
    'instruction_recommendations' => []];
$answer = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
echo match ($scenario) {
    'empty' => '', 'invalid_json' => '{', 'multiple' => $answer."\n".$answer,
    'prefix' => "Explanation\n".$answer, 'invalid_utf8' => "\xff",
    'foreign_schema' => '{"schema_version":"foreign"}', 'fenced' => "```json\n".$answer."\n```",
    default => $answer,
};
