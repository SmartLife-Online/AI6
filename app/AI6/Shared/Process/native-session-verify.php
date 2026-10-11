<?php

// Standalone, immutable child verifier. No application bootstrap or secret input.
set_error_handler(static function (int $severity): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new RuntimeException('Native session verification failed.');
});
try {
    if (! isset($argc, $argv) || $argc !== 7) {
        throw new RuntimeException;
    }
    [, $source, $target, $identity, $contextHash, $runtimeHash, $role] = $argv;
    $input = '/var/lib/ai6/agent-executions';
    $output = '/var/lib/ai6/agent-outputs';
    $suffix = substr($target, strlen($input));
    if (! str_starts_with($target, $input.'/')
        || preg_match('~\A/execution-[0-9a-f]{32}/[A-Za-z0-9._-]+-[A-Za-z0-9._-]+-[0-9a-f]{16}/home/session-state\z~D', $suffix) !== 1
        || $source !== $output.substr($suffix, 0, -strlen('/home/session-state')).'/result/copilot-invocation/result/copilot-session-state'
        || ! in_array($role, ['quality_review', 'security_review'], true)
        || preg_match('/\A[0-9a-f]{64}\z/D', $contextHash) !== 1
        || preg_match('/\A[0-9a-f]{64}\z/D', $runtimeHash) !== 1) {
        throw new RuntimeException;
    }
    foreach ([$source, $target] as $path) {
        if (! is_dir($path) || is_link($path) || realpath($path) !== $path) {
            throw new RuntimeException;
        }
        $stat = stat($path);
        if ($identity !== $stat['dev'].':'.$stat['ino']) {
            throw new RuntimeException;
        }
    }
    if (scandir($source) !== ['.', '..']) {
        throw new RuntimeException;
    }
    $root = dirname(dirname($target));
    $bytes = file_get_contents($root.'/runtime/turn.json');
    $context = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
    $runtime = json_decode(file_get_contents($root.'/runtime/profile.json'), true, 32, JSON_THROW_ON_ERROR);
    if (hash('sha256', $bytes) !== $contextHash || $context['role'] !== $role
        || $context['instruction_snapshot']['provider_profile_alias'] !== 'github_copilot_cli'
        || $context['runtime_profile_id'] !== $runtime['id'] || $runtime['hash'] !== $runtimeHash
        || $runtime['version'] !== 2 || $runtime['adapter_flags'] !== []
        || $runtime['permissions'] !== ['native_session_state' => 'ephemeral_review', 'network' => false, 'workspace' => 'read_only']) {
        throw new RuntimeException;
    }
    $mounts = [];
    foreach (explode("\n", file_get_contents('/proc/self/mountinfo')) as $line) {
        $fields = explode(' ', $line);
        if (count($fields) >= 6) {
            $mounts[$fields[4]][] = explode(',', $fields[5]);
        }
    }
    if (count($mounts[$target] ?? []) !== 1
        || array_diff(['rw', 'nosuid', 'nodev', 'noexec'], $mounts[$target][0]) !== []
        || count($mounts[$root] ?? []) !== 1 || ! in_array('ro', $mounts[$root][0], true)) {
        throw new RuntimeException;
    }
    // All nested mounts inside the sealed input home must remain read-only,
    // except this exact session inode. A second writable input mount is rejected.
    foreach ($mounts as $path => $entries) {
        if (str_starts_with($path, $root.'/') && $path !== $target) {
            foreach ($entries as $options) {
                if (! in_array('ro', $options, true)) {
                    throw new RuntimeException;
                }
            }
        }
    }
    $probe = $target.'/.ai6-write-probe-'.bin2hex(random_bytes(16));
    $handle = fopen($probe, 'xb');
    fclose($handle);
    if (! unlink($probe)) {
        throw new RuntimeException;
    }
    // A real mount observation, rather than rewriting the values being checked.
    foreach ([$root.'/home', $root.'/workspace', $root.'/runtime', $root.'/instructions'] as $path) {
        $probe = $path.'/.ai6-write-probe-'.bin2hex(random_bytes(16));
        $handle = @fopen($probe, 'xb');
        if (is_resource($handle)) {
            fclose($handle);
            unlink($probe);
            throw new RuntimeException;
        }
    }
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "The native session boundary could not be verified.\n");
    exit(78);
}
