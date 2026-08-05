<?php

namespace App\AI6\Git;

use App\AI6\Shared\Process\ImmutableRuntimeFile;
use InvalidArgumentException;

final class HardenedGitEnvironment
{
    public function __construct(private readonly GitConfiguration $configuration) {}

    /** @return list<string> */
    public function commandPrefix(): array
    {
        return [
            $this->configuration->gitBinary,
            '--no-pager',
            '-c', 'core.hooksPath='.$this->configuration->hooksPath,
            '-c', 'core.attributesFile=/dev/null',
            '-c', 'core.fsmonitor=false',
            '-c', 'core.untrackedCache=false',
            '-c', 'credential.helper=',
            '-c', 'credential.useHttpPath=true',
            '-c', 'diff.external=',
            '-c', 'commit.gpgSign=false',
            '-c', 'tag.gpgSign=false',
            '-c', 'log.showSignature=false',
            '-c', 'merge.verifySignatures=false',
            '-c', 'submodule.recurse=false',
            '-c', 'fetch.recurseSubmodules=false',
            '-c', 'push.recurseSubmodules=no',
            '-c', 'protocol.allow=never',
            '-c', 'protocol.ssh.allow=always',
            '-c', 'protocol.file.allow=never',
        ];
    }

    /** @return array<string, string> */
    public function variables(?string $privateKey = null, ?string $knownHosts = null): array
    {
        $this->assertImmutableRuntimeFile($this->configuration->gitBinary, 'Git binary');
        $this->assertImmutableRuntimeFile($this->configuration->sshBinary, 'Git SSH binary');
        $this->assertRuntimePath($this->configuration->executionHome, true, 'Git execution home', 0700);
        $this->assertRuntimePath($this->configuration->xdgConfigHome, true, 'Git XDG config home', 0700);
        $this->assertRuntimePath($this->configuration->globalConfig, false, 'Git global config', 0444);
        $this->assertRuntimePath($this->configuration->hooksPath, true, 'Git hooks path', 0555);
        $this->assertImmutableRuntimeFile($this->configuration->sshWrapper, 'Git SSH wrapper');

        $hooks = array_values(array_diff(scandir($this->configuration->hooksPath) ?: [], ['.', '..']));
        if ($hooks !== []) {
            throw new InvalidArgumentException('Git hooks path must be empty.');
        }

        $variables = [
            'HOME' => $this->configuration->executionHome,
            'XDG_CONFIG_HOME' => $this->configuration->xdgConfigHome,
            'XDG_CACHE_HOME' => $this->configuration->executionHome.'/cache',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => $this->configuration->globalConfig,
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_PAGER' => '',
            'GIT_EXTERNAL_DIFF' => '',
            'GIT_SSH' => $this->configuration->sshWrapper,
            'GIT_SSH_VARIANT' => 'simple',
            'AI6_GIT_SSH_BINARY' => $this->configuration->sshBinary,
            'LC_ALL' => 'C.UTF-8',
            'PATH' => $this->configuration->executablePath,
        ];

        if (DIRECTORY_SEPARATOR === '\\') {
            foreach (['SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TMP', 'TEMP'] as $name) {
                $value = getenv($name);
                if (is_string($value) && $value !== '') {
                    $variables[$name] = $value;
                }
            }
        }

        if ($privateKey !== null || $knownHosts !== null) {
            if ($privateKey === null || $knownHosts === null) {
                throw new InvalidArgumentException('Git SSH credentials must provide both key and known_hosts paths.');
            }

            $this->assertCredentialFile($privateKey, true);
            $this->assertCredentialFile($knownHosts, false);
            $variables['AI6_GIT_SSH_KEY'] = $privateKey;
            $variables['AI6_GIT_KNOWN_HOSTS'] = $knownHosts;
        }

        return $variables;
    }

    private function assertImmutableRuntimeFile(string $path, string $name): void
    {
        $violation = ImmutableRuntimeFile::violation($path, true);
        if ($violation !== null) {
            throw new InvalidArgumentException($name.' '.$violation.'.');
        }
    }

    /** @param int|list<int> $posixMode */
    private function assertRuntimePath(string $path, bool $directory, string $name, int|array $posixMode): void
    {
        $real = realpath($path);
        if ($real === false || $real !== $path || is_link($path) || ($directory ? ! is_dir($path) : ! is_file($path))) {
            throw new InvalidArgumentException($name.' is unavailable or not canonical.');
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($path);
            $allowedModes = is_int($posixMode) ? [$posixMode] : $posixMode;
            if (! is_int($mode) || ! in_array($mode & 0777, $allowedModes, true)) {
                throw new InvalidArgumentException($name.' has an unsafe mode.');
            }
        }
    }

    private function assertCredentialFile(string $path, bool $private): void
    {
        $real = realpath($path);
        if ($real === false || $real !== $path || is_link($path) || ! is_file($path)) {
            throw new InvalidArgumentException('A Git SSH credential path is unavailable or not canonical.');
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $mode = fileperms($path);
            if (! is_int($mode) || ($private ? ($mode & 0077) !== 0 : ($mode & 0022) !== 0)) {
                throw new InvalidArgumentException('A Git SSH credential file has an unsafe mode.');
            }
        }
    }
}
