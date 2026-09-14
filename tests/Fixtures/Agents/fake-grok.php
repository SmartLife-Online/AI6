<?php

// Standalone native transport double; no application bootstrap or provider network.
if (! isset($argv)) {
    exit(2);
}
$scenario = $argv[1] ?? 'success';
$arguments = array_slice($argv, 2);
$home = (string) getenv('GROK_HOME');
$result = (string) getenv('TMPDIR');
if ($arguments === ['--no-auto-update', '--version']) {
    echo $scenario === 'version_drift' ? "grok 1.0.6 (foreign)\n" : "grok 1.0.5 (5115b46bc9)\n";
    exit(0);
}
if (in_array('inspect', $arguments, true)) {
    $cells = [];
    foreach (['claude', 'cursor', 'codex'] as $vendor) {
        foreach ($vendor === 'codex' ? ['sessions'] : ['skills', 'rules', 'agents', 'mcps', 'hooks', 'sessions'] as $surface) {
            $cells[] = ['vendor' => $vendor, 'surface' => $surface, 'enabled' => getenv('GROK_'.strtoupper($vendor.'_'.$surface).'_ENABLED') !== '0', 'source' => 'env'];
        }
    }
    $instructions = [];
    foreach (['AGENTS.md', 'Agents.md', 'AGENT.md', 'CLAUDE.md', 'Claude.md', 'CLAUDE.local.md'] as $name) {
        if (is_file(getcwd().'/'.$name)) {
            $instructions[] = ['path' => getcwd().'/'.$name, 'scope' => 'project', 'fileType' => 'agents_md'];
        }
    }
    echo json_encode(['grokVersion' => '1.0.5', 'cwd' => getcwd(), 'projectRoot' => null, 'projectInstructions' => $instructions,
        'hooks' => [], 'skills' => $scenario === 'enabled_skill' ? [['name' => 'foreign']] : [],
        'plugins' => $scenario === 'foreign_extension' ? [['name' => 'foreign']] : [], 'marketplaces' => [], 'mcpServers' => [], 'lspServers' => [],
        'agents' => array_map(static fn (string $name): array => ['name' => $name, 'source' => ['type' => 'builtin']], ['general-purpose', 'explore', 'plan']),
        'permissions' => ['sources' => [], 'loaded' => 0, 'skipped' => [], 'mcpServerAllowlist' => [], 'marketplaceAllowlist' => [], 'managedSettingsExists' => false, 'managedSettingsActive' => false],
        'configSources' => ['layers' => [['role' => 'user', 'path' => $home.'/config.toml']]],
        'externalCompat' => ['remoteSettingsLoaded' => false, 'cells' => $cells],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit(0);
}
$index = array_search('--prompt-file', $arguments, true);
if ($index === false || ! is_file($arguments[$index + 1])) {
    exit(12);
}
$prompt = (string) file_get_contents($arguments[$index + 1]);
$env = [];
foreach (['HOME', 'GROK_HOME', 'XAI_API_KEY', 'TMPDIR', 'APP_KEY', 'DB_DATABASE', 'MAIL_PASSWORD', 'GITHUB_TOKEN', 'GH_TOKEN', 'AI6_GIT_SSH_KEY', 'CODEX_HOME', 'XDG_CONFIG_HOME'] as $name) {
    $env[$name] = getenv($name) !== false ? 'present' : 'missing';
}
file_put_contents($result.'/observation.json', json_encode(['argv' => $arguments, 'env' => $env, 'prompt_bytes' => strlen($prompt), 'prompt_sha256' => hash('sha256', $prompt)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
if (getenv('XAI_API_KEY') === false) {
    $expected = ['--no-auto-update', '--no-memory', '--no-subagents', '--no-plan', '--disable-web-search',
        '--max-turns', '16', '--verbatim', '--tools', 'read_file,list_dir,grep', '--disallowed-tools', 'search_tool,use_tool,Agent',
        '--permission-mode', 'dontAsk', '--sandbox', 'ai6-review', '--cwd', getcwd(),
        '--output-format', 'streaming-messages-json', '--prompt-file', $result.'/doctor-prompt.txt'];
    if ($arguments !== $expected || $prompt !== 'AI6 credential-free sandbox preparation probe.'
        || file_exists(implode(DIRECTORY_SEPARATOR, [$home, 'auth', 'token'])) || ! is_dir($home.'/sessions')) {
        exit(14);
    }
    $sandbox = (string) file_get_contents($home.'/sandbox.toml');
    preg_match('/^deny = (.+)$/m', $sandbox, $denyLine);
    $denies = json_decode($denyLine[1] ?? '[]', true, 8, JSON_THROW_ON_ERROR);
    $matched = [];
    foreach ($denies as $pattern) {
        array_push($matched, ...glob($pattern));
    }
    if (! in_array($home.'/auth', $matched, true) || ! in_array(dirname($home).'/runtime', $matched, true)) {
        fwrite(STDERR, "Doctor home is outside the configured deny paths.\n");
        exit(15);
    }
    if ($scenario === 'sandbox_bad_json') {
        echo '{';
        exit(1);
    }
    if ($scenario === 'sandbox_unprepared') {
        fwrite(STDERR, "error: could not create bwrap placeholder for read-deny path; refusing to start with a partial sandbox\n");
        exit(1);
    }
    if ($scenario === 'sandbox_namespace') {
        fwrite(STDERR, "bwrap: Creating new namespace failed: Operation not permitted\n");
        exit(1);
    }
    if ($scenario !== 'sandbox_missing_init') {
        echo json_encode(['type' => 'system', 'subtype' => 'init'])."\n";
    }
    if ($scenario === 'sandbox_duplicate_init') {
        echo json_encode(['type' => 'system', 'subtype' => 'init'])."\n";
    }
    echo json_encode(['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true, 'num_turns' => 0,
        'errors' => ['Not signed in. To authenticate without a browser, run: grok login --device-code']])."\n";
    fwrite(STDERR, "Error: Not signed in. To authenticate without a browser, run: grok login --device-code\n");
    exit($scenario === 'sandbox_wrong_exit' ? 0 : 1);
}
// Exercise the actual server link; fresh invocation state belongs only to the result tree.
if (! is_link($home.'/sessions') || file_exists($home.'/sessions/native.json')) {
    exit(13);
}
file_put_contents($home.'/sessions/native.json', '{"session":"fresh"}');
$context = json_decode((string) file_get_contents(dirname($home).'/runtime/turn.json'), true, 64, JSON_THROW_ON_ERROR);
$constant = static function (string $name) use ($prompt): string {
    preg_match('/^'.preg_quote($name, '/').': (.+)$/m', $prompt, $matches);

    return $matches[1] ?? 'unbound';
};
$document = ['schema_version' => $constant('schema_version'), 'status' => 'nothing_to_fix', 'summary' => 'Deterministischer Grok-Review.',
    'prompt_snapshot_hash' => $constant('prompt_snapshot_hash'), 'instruction_snapshot_hash' => $constant('instruction_snapshot_hash'),
    'provider_runtime_profile_hash' => $constant('provider_runtime_profile_hash'), 'human_request' => null,
    'findings' => [], 'criterion_coverage' => array_map(static fn (string $id): array => ['criterion_id' => $id, 'status' => 'satisfied', 'evidence' => 'Fixture.'], $context['criterion_refs']), 'instruction_recommendations' => []];
if ($document['schema_version'] === 'ai6.finding-verification.v1') {
    unset($document['findings'], $document['criterion_coverage'], $document['instruction_recommendations']);
    $document['status'] = 'clear';
    $document['verification'] = ['finding_id' => $scenario === 'foreign_finding' ? 'finding-2' : ($context['expected_finding_ids'][0] ?? null),
        'duplicate_group' => null, 'assessment' => 'confirmed', 'recommendation' => 'confirm', 'evidence' => 'Synthetischer Nachweis.'];
}
$answer = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$final = ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'num_turns' => 2,
    'result' => match ($scenario) {
        'invalid_json', 'cleanup_invalid' => '{', 'foreign_schema' => '{"schema_version":"foreign"}', default => $answer
    }];
if ($scenario !== 'missing_usage') {
    $final['usage'] = ['input_tokens' => $scenario === 'null_usage' ? null : 12, 'output_tokens' => $scenario === 'null_usage' ? 3 : 0];
}
$final['usage']['server_tool_use']['web_search_requests'] = $scenario === 'web_search' ? 1 : 0;
if ($scenario === 'missing_usage') {
    unset($final['num_turns']);
}
if ($scenario === 'invalid_num_turns') {
    $final['num_turns'] = 'two';
}
if ($scenario === 'missing_web_usage') {
    unset($final['usage']['server_tool_use']);
}
if (in_array($scenario, ['all-zero', 'all-null', 'zero_tokens_cost'], true)) {
    $final['usage'] = array_replace($final['usage'], array_fill_keys(['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_creation_input_tokens'], $scenario === 'all-null' ? null : 0));
    $final['total_cost_usd'] = 0;
    $final['duration_api_ms'] = 99;
}
if (in_array($scenario, ['positive_cost', 'zero_tokens_cost'], true)) {
    $final['total_cost_usd'] = 0.25;
    $final['duration_api_ms'] = 99;
}
if ($scenario === 'max_turns') {
    $final['subtype'] = 'error_max_turns';
    $final['is_error'] = true;
}
if (in_array($scenario, ['cleanup_invalid', 'cleanup_success'], true)) {
    unlink($home.'/sessions/native.json');
    rmdir($result.'/grok-sessions');
    file_put_contents($result.'/grok-sessions', 'cleanup obstruction');
}
if ($scenario === 'missing_answer') {
    unset($final['result']);
}
if ($scenario === 'empty') {
    exit(0);
}
$modelIndex = array_search('--model', $arguments, true);
$init = ['type' => 'system', 'subtype' => 'init', 'model' => $scenario === 'init_model' ? 'foreign-model' : ($modelIndex === false ? 'native-default' : $arguments[$modelIndex + 1]), 'tools' => ['read_file', 'list_dir', 'grep'], 'mcp_servers' => [], 'skills' => [], 'permissionMode' => 'dontAsk', 'cwd' => getcwd()];
foreach (['tools' => ['read_file'], 'mcp_servers' => ['foreign'], 'skills' => ['foreign'], 'permissionMode' => 'default', 'cwd' => dirname(getcwd())] as $key => $value) {
    if ($scenario === 'init_'.$key) {
        $init[$key] = $value;
    }
}
if (! in_array($scenario, ['missing_init', 'late_init'], true)) {
    echo json_encode($init, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}
if ($scenario === 'multiple_init') {
    echo json_encode($init, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}
echo json_encode(['type' => 'assistant', 'message' => ['content' => 'Not the final answer.']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
echo json_encode($final, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
if ($scenario === 'late_init') {
    echo json_encode($init, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}
if ($scenario === 'timeout') {
    $deadline = microtime(true) + 120;
    while (microtime(true) < $deadline) {
        file_put_contents($result.'/pulse', (string) microtime(true));
        usleep(10000);
    }
}
if ($scenario === 'output_limit') {
    echo str_repeat('x', 20000000);
    exit(0);
}
if (in_array($scenario, ['multiple', 'multiple_missing_usage'], true)) {
    if ($scenario === 'multiple_missing_usage') {
        unset($final['usage']);
    }
    echo json_encode($final, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}
if ($scenario === 'truncated') {
    echo '{"type":';
}
if ($scenario === 'invalid_utf8') {
    echo "\xff\n";
}
if ($scenario === 'exit_failure') {
    fwrite(STDERR, 'provider failure: permission denied; token=synthetic-diagnostic-secret');
    exit(9);
}
