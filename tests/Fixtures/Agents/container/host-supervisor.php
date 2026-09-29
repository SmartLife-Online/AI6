<?php

// Host-side test driver. The request can select no image, command, device or arbitrary mount.
$root = $argv[1] ?? '';
if (! preg_match('~\A/tmp/ai6-native\.[a-zA-Z0-9]+\z~D', $root) || realpath($root) !== $root) {
    throw new RuntimeException('Expected a dedicated canonical test directory.');
}
$configuration = json_decode((string) file_get_contents($root.'/harness/launcher.json'), true, 8, JSON_THROW_ON_ERROR);
$image = $configuration['image'];
$identity = $configuration['identity'];
if (! is_string($image) || ! preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)
    || ! is_string($identity) || ! preg_match('/\Aai6-native-[a-f0-9]{16}\z/D', $identity)) {
    throw new RuntimeException('Invalid immutable test image or resource identity.');
}
$source = $root.'/source';
$work = $root.'/work';
$bridge = $root.'/bridge';
echo "Fixed test supervisor ready.\n";
while (! is_file($bridge.'/stop')) {
    foreach (glob($bridge.'/*.request') ?: [] as $file) {
        $id = basename($file, '.request');
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $id) || is_file($bridge.'/'.$id.'.response')) {
            continue;
        }
        $base = $bridge.'/'.$id;
        try {
            $request = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            if (($request['schema'] ?? null) !== 'ai6.test-supervisor.v1' || ! is_int($request['expires_at'] ?? null)
                || $request['expires_at'] < time() || $request['expires_at'] > time() + 120) {
                throw new RuntimeException('Invalid or expired isolated test request.');
            }
            $settings = $request['settings'];
            $command = ['sudo', '-n', 'docker', 'run', '--rm', '--name', $identity.'-agent', '--user', '1000:1000', '--cpus', '2', '--memory', '1g',
                '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
                '--security-opt', 'apparmor=:'.$identity.'://ai6-agent-v1', '--security-opt', 'systempaths=unconfined',
                '--security-opt', 'seccomp='.$source.'/docker/agent-seccomp-moby-29.6.1.json',
                '--tmpfs', '/tmp:rw,nosuid,mode=1777', '-e', 'APP_ENV=testing', '-e', 'HOME=/tmp', '-e', 'APP_KEY=base64:'.base64_encode(str_repeat('x', 32)),
                '-v', $source.':'.$source.':ro', '-v', $work.':/work:ro',
                '-v', $identity.'-inputs:/var/lib/ai6/agent-executions:ro',
                '-v', $identity.'-outputs:/var/lib/ai6/agent-outputs',
                '-v', $identity.'-private:/run/ai6/provider-private'];
            foreach (['store_root' => 'store', 'report_root' => 'reports', 'presence_root' => 'presence'] as $key => $leaf) {
                $path = $settings['ai6.provider_onboarding'][$key] ?? null;
                if (! is_string($path) || ! preg_match('~\A/work/ai6-onboarding-[a-f0-9]{16}/'.$leaf.'\z~D', $path)) {
                    throw new RuntimeException('Unexpected isolated provider-state path.');
                }
                $hostPath = $work.substr($path, strlen('/work'));
                if (! is_dir($hostPath) || realpath($hostPath) !== $hostPath) {
                    throw new RuntimeException('The isolated provider-state path is not canonical.');
                }
                array_push($command, '-v', $hostPath.':'.$path);
            }
            array_push($command, '--workdir', $source, '--entrypoint', 'php', $image,
                '-d', 'zend.exception_ignore_args=1', $source.'/tests/Fixtures/Agents/provider-supervisor.php',
                '/var/lib/ai6/agent-executions/native-supervisor-test.json');
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $base.'.stdout', 'w'], 2 => ['file', $base.'.stderr', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('The isolated test supervisor could not start.');
            }
            $exit = proc_close($process);
        } catch (Throwable $error) {
            file_put_contents($base.'.stdout', '');
            file_put_contents($base.'.stderr', $error->getMessage());
            $exit = 125;
        }
        file_put_contents($base.'.pending-response', json_encode(['exit_code' => $exit], JSON_THROW_ON_ERROR));
        rename($base.'.pending-response', $base.'.response');
        echo $id.' exit='.$exit."\n";
    }
    usleep(100000);
    clearstatcache();
}
echo "Fixed test supervisor stopped.\n";
