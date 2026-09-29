<?php

use Tests\Fixtures\Agents\FakeCopilotBinary;

require dirname(__DIR__, 4).'/vendor/autoload.php';
$scenarios = ['success', 'fenced', 'null_usage', 'missing_usage', 'empty', 'invalid_json', 'multiple', 'prefix', 'invalid_utf8', 'version_drift', 'enabled_skill', 'foreign_extension', 'exit_failure', 'native_state', 'timeout', 'output_limit', 'foreign_schema', 'missing_auth', 'no_evidence', 'network', 'security_clear', 'security_security_findings', 'security_needs_human', 'security_inconclusive', 'model_disabled', 'models_offline', 'models_wrong_id', 'models_duplicate', 'models_truncated', 'models_multiple', 'login_fail'];
foreach ($scenarios as $scenario) {
    $path = FakeCopilotBinary::create('/out', $scenario);
    rename($path, '/out/copilot-'.hash_file('sha256', $path));
}
