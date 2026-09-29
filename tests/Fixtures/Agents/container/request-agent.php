<?php

// Test-only client of the fixed supervisor launcher. No Docker socket in this container.
if (! isset($argv) || count($argv) !== 2 || $argv[1] !== '/var/lib/ai6/agent-executions/native-supervisor-test.json') {
    throw new RuntimeException('Unexpected supervisor test input.');
}
$settings = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$id = bin2hex(random_bytes(16));
$base = '/test-control/'.$id;
$request = ['schema' => 'ai6.test-supervisor.v1', 'expires_at' => time() + 110, 'settings' => $settings];
file_put_contents($base.'.pending', json_encode($request, JSON_THROW_ON_ERROR));
rename($base.'.pending', $base.'.request');
$deadline = microtime(true) + 110;
while (! is_file($base.'.response')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('The external test supervisor did not respond.');
    }
    usleep(100000);
    clearstatcache();
}
$result = json_decode(file_get_contents($base.'.response'), true, 8, JSON_THROW_ON_ERROR);
echo file_get_contents($base.'.stdout');
fwrite(STDERR, file_get_contents($base.'.stderr'));
exit($result['exit_code']);
