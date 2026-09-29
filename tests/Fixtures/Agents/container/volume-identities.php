<?php

$paths = ['/var/lib/ai6/agent-executions', '/var/lib/ai6/agent-outputs', '/run/ai6/provider-private'];
$devices = [];
foreach ($paths as $path) {
    $devices[$path] = stat($path)['dev'];
}
echo json_encode(['identity' => getenv('AI6_NATIVE_PROVIDER_TEST_ID'), 'devices' => $devices], JSON_THROW_ON_ERROR);
