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
if ($arguments === ['--headless', '--stdio', '--no-auto-update', '--no-auto-login', '--log-level', 'none']) {
    $header = fgets(STDIN);
    if (! is_string($header) || preg_match('/\AContent-Length: ([1-9][0-9]{0,6})\r\n\z/', $header, $match) !== 1
        || fgets(STDIN) !== "\r\n") {
        exit(9);
    }
    $body = '';
    while (strlen($body) < (int) $match[1] && ! feof(STDIN)) {
        $body .= (string) fread(STDIN, (int) $match[1] - strlen($body));
    }
    $wire = $header."\r\n".$body;
    $parts = explode("\r\n\r\n", $wire, 2);
    $request = isset($parts[1]) ? json_decode($parts[1], true, 16, JSON_THROW_ON_ERROR) : [];
    if (($request['method'] ?? null) !== 'models.list' || ($request['params']['gitHubToken'] ?? null) !== 'synthetic-copilot-token'
        || $parts[0] !== 'Content-Length: '.strlen($parts[1])) {
        exit(9);
    }
    $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['models' => [
        ['id' => 'auto', 'policy' => ['state' => $scenario === 'model_disabled' ? 'disabled' : 'enabled']],
        ['id' => 'claude-sonnet-4.6', 'policy' => ['state' => 'disabled']],
    ]]];
    if ($scenario === 'models_offline') {
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32603, 'message' => 'No account model evidence.']];
    } elseif ($scenario === 'models_wrong_id') {
        $response['id'] = 2;
    } elseif ($scenario === 'models_duplicate') {
        $response['result']['models'][] = $response['result']['models'][0];
    }
    $body = json_encode($response, JSON_THROW_ON_ERROR);
    $wire = 'Content-Length: '.strlen($body)."\r\n\r\n".$body;
    if ($scenario === 'models_slow') {
        usleep(1500000);
    }
    echo $scenario === 'models_truncated' ? substr($wire, 0, -1) : $wire;
    if ($scenario === 'models_multiple') {
        echo $wire;
    }
    if ($scenario === 'models_exit_failure') {
        exit(9);
    }
    if (! in_array($scenario, ['models_truncated', 'models_wrong_id', 'models_duplicate', 'models_offline'], true)) {
        stream_get_contents(STDIN);
    }
    exit(0);
}
if ($arguments === ['login', '--with-token']) {
    $input = trim((string) stream_get_contents(STDIN));
    if (in_array($scenario, ['login_fail', 'login_consent_required'], true) || $input !== 'synthetic-copilot-token') {
        exit(9);
    }
    file_put_contents($nativeHome.'/login-history', 'private-login-bait');
    echo 'Provider returned token='.$input."\n";
    exit(0);
}
if ($arguments === ['--version']) {
    echo $scenario === 'version_drift' ? "GitHub Copilot CLI 1.0.84.\n" : "GitHub Copilot CLI 1.0.83.\n";
    if ($scenario === 'version_hint') {
        echo "Run 'copilot update' to check for updates.\n";
    }
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
if (str_starts_with($scenario, 'security_')) {
    $document['status'] = substr($scenario, strlen('security_'));
    if ($scenario === 'security_unknown_disposition') {
        $document['status'] = 'security_findings';
    }
    // The native mailbox must expose the exact candidate, without writable input or worker credentials.
    if (! is_file('app/Example.php') || file_get_contents('app/Example.php') !== "<?php\n\n// Bound candidate.\n"
        || file_exists('.git') || @file_put_contents('app/Example.php', 'mutated') !== false
        || @file_put_contents('forbidden.txt', 'mutated') !== false
        || getenv('MAIL_PASSWORD') !== false || getenv('AI6_GIT_SSH_KEY') !== false) {
        exit(11);
    }
    if ($document['status'] === 'security_findings') {
        $document['findings'] = [['local_id' => 'security-1', 'severity' => 'critical', 'disposition' => 'must_fix',
            'category' => 'security', 'file' => 'app/Example.php', 'line' => 1, 'title' => 'Deterministischer Securitybefund',
            'evidence' => 'Synthetischer Testbefund.', 'expected_result' => 'Menschliche Prüfung.', 'criterion_refs' => []]];
        if ($scenario === 'security_unknown_disposition') {
            $document['findings'][0]['disposition'] = 'resolved';
        }
    }
    if ($document['status'] === 'needs_human') {
        $document['human_request'] = ['kind' => 'clarification', 'title' => 'Securityprüfung', 'message' => 'Entscheidung benötigt.',
            'why_needed' => 'Synthetische Unsicherheit.', 'response_mode' => 'select',
            'options' => [['key' => 'a', 'label' => 'Prüfen'], ['key' => 'b', 'label' => 'Abbrechen']],
            'recommended_option' => 'a', 'affected_paths' => ['app/Example.php'], 'criterion_refs' => []];
    }
}
$answer = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$payload = match ($scenario) {
    'empty' => '', 'invalid_json' => '{', 'multiple' => $answer."\n".$answer,
    'prefix' => "Explanation\n".$answer, 'invalid_utf8' => "\xff",
    'foreign_schema' => '{"schema_version":"foreign"}', 'fenced' => "```json\n".$answer."\n```",
    default => $answer,
};
$format = array_search('--output-format', $arguments, true);
if ($format !== false && ($arguments[$format + 1] ?? null) === 'json' && $scenario !== 'invalid_utf8') {
    foreach ([['type' => 'assistant.message', 'data' => ['content' => $payload, 'toolRequests' => [], 'turnId' => 'fixture-turn']],
        ['type' => 'assistant.turn_end', 'data' => ['turnId' => 'fixture-turn']],
        ['type' => 'result', 'exitCode' => 0]] as $event) {
        echo json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
    }
} else {
    echo $payload;
}
