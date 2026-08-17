<?php

namespace App\AI6\Shared\Process;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final readonly class ProcessIsolationVerifier implements ProcessIsolationBoundary
{
    public function assertIsolated(ProcessRequest $request, ProcessPolicy $policy): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || config('ai6.runtime_role') !== $policy->name->value) {
            throw new ProcessStartRejectedException('The process runtime role is not isolated.');
        }

        $inputRoot = config('ai6.execution_mailboxes.'.$policy->name->value.'_root');
        $outputRoot = config('ai6.execution_mailboxes.'.$policy->name->value.'_output_root');
        if (! is_string($inputRoot) || ! is_string($outputRoot)
            || ! $this->within($request->workingDirectory, $inputRoot)
            || $request->resultDirectory === null || ! $this->within($request->resultDirectory, $outputRoot)
            || $request->artifactDirectory === null || ! $this->within($request->artifactDirectory, $outputRoot)
            || realpath($inputRoot) === realpath($outputRoot)) {
            throw new ProcessStartRejectedException('The process input and output roots are not isolated.');
        }

        $home = dirname((string) realpath($request->workingDirectory));
        foreach ([$home.'/instructions', $home.'/home/auth', $home.'/runtime/profile.json'] as $protected) {
            if (! file_exists($protected) || is_link($protected) || is_writable($protected)) {
                throw new ProcessStartRejectedException('The process read-only projection is not isolated.');
            }
        }
        $this->assertTree($home);
        $this->assertInstructionBindings($home);
        $this->assertMount($inputRoot, true);
        $this->assertMount($outputRoot, false);
        $this->assertRootReadOnly();

        if ($policy->name === ProcessPolicyName::CHECKER) {
            $interfaces = array_values(array_diff(scandir('/sys/class/net') ?: [], ['.', '..', 'lo']));
            if ($interfaces !== []) {
                throw new ProcessStartRejectedException('The checker network boundary is not isolated.');
            }
        }
    }

    private function assertTree(string $home): void
    {
        $forbidden = ['.git', '.codex', '.claude', '.mcp.json', 'mcp.json'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($home, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || in_array($entry->getFilename(), $forbidden, true)) {
                throw new ProcessStartRejectedException('The process tree contains a forbidden discovery or Git path.');
            }
        }
    }

    private function assertInstructionBindings(string $home): void
    {
        $overlay = $home.'/instructions';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($overlay, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            $relative = substr($entry->getPathname(), strlen($overlay) + 1);
            $native = $home.'/workspace/'.$relative;
            $overlayBytes = file_get_contents($entry->getPathname());
            $nativeBytes = file_get_contents($native);
            if (! is_string($overlayBytes) || ! is_string($nativeBytes) || ! hash_equals(hash('sha256', $overlayBytes), hash('sha256', $nativeBytes))) {
                throw new ProcessStartRejectedException('The native instruction snapshot binding is invalid.');
            }
        }
    }

    private function assertMount(string $path, bool $readOnly): void
    {
        $options = $this->mountOptions((string) realpath($path));
        foreach (['nosuid', 'nodev', 'noexec'] as $required) {
            if (! in_array($required, $options, true)) {
                throw new ProcessStartRejectedException('The execution mount isolation is incomplete.');
            }
        }
        if ($readOnly && ! in_array('ro', $options, true)) {
            throw new ProcessStartRejectedException('The execution input mount is not read-only.');
        }
    }

    private function assertRootReadOnly(): void
    {
        if (! in_array('ro', $this->mountOptions('/'), true)) {
            throw new ProcessStartRejectedException('The executor container root is not read-only.');
        }
    }

    /** @return list<string> */
    private function mountOptions(string $path): array
    {
        $lines = file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $selected = null;
        $selectedLength = -1;
        foreach (is_array($lines) ? $lines : [] as $line) {
            $parts = explode(' ', $line);
            $separator = array_search('-', $parts, true);
            if ($separator === false || ! isset($parts[4], $parts[5], $parts[$separator + 3])) {
                continue;
            }
            $mount = str_replace(['\\040', '\\011', '\\134'], [' ', "\t", '\\'], $parts[4]);
            if (($path === $mount || str_starts_with($path, rtrim($mount, '/').'/')) && strlen($mount) > $selectedLength) {
                $selected = array_values(array_unique([...explode(',', $parts[5]), ...explode(',', $parts[$separator + 3])]));
                $selectedLength = strlen($mount);
            }
        }
        if ($selected === null) {
            throw new ProcessStartRejectedException('The execution mount could not be verified.');
        }

        return $selected;
    }

    private function within(string $path, string $root): bool
    {
        $resolvedPath = realpath($path);
        $resolvedRoot = realpath($root);

        return is_string($resolvedPath) && is_string($resolvedRoot)
            && ($resolvedPath === $resolvedRoot || str_starts_with($resolvedPath, rtrim($resolvedRoot, '/').'/'));
    }
}
