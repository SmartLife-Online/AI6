<?php

namespace App\AI6\Git;

use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use RuntimeException;

/**
 * Produce the optional sanitized history context as a separate, technically read-only artifact.
 *
 * The result is never the managed clone and never a path into it: it is a freshly created
 * directory holding one read-only file with redacted, identity-free history metadata.
 */
final readonly class RunHistoryContext
{
    private const FILE_NAME = 'history.txt';

    public function __construct(private HardenedGitRunner $git, private Redactor $redactor) {}

    /**
     * @param  int  $limit  how many commits the projection may contain
     * @return string the absolute path of the read-only history file
     */
    public function export(Run $run, string $destination, int $limit, RedactionContext $context): string
    {
        if ($run->worktree_path === null || $run->checkpoint_commit_sha === null) {
            throw new RuntimeException('A history context requires a bound workspace and checkpoint.');
        }
        if ($destination === '' || str_contains($destination, "\0")) {
            throw new RuntimeException('The history context destination is invalid.');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('The history context destination already exists.');
        }

        $parent = $this->regularDirectory(dirname($destination));
        $worktree = $this->regularDirectory($run->worktree_path);
        if ($parent === $worktree || str_starts_with($parent.DIRECTORY_SEPARATOR, $worktree.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The history context must not be created inside the managed workspace.');
        }

        $history = $this->git->readRunHistory($run->worktree_path, $run->checkpoint_commit_sha, $limit, $context);
        if (! $history->succeeded()) {
            throw new RuntimeException('The run history context could not be resolved.');
        }
        // The UTF-8/redaction gate runs before the untrusted subject bytes reach any artifact.
        $redacted = $this->redactor->redact($history->output, $context)->text;

        if (! mkdir($destination, 0700)) {
            throw new RuntimeException('The history context directory could not be created.');
        }
        $file = $destination.DIRECTORY_SEPARATOR.self::FILE_NAME;
        if (file_put_contents($file, $redacted, LOCK_EX) !== strlen($redacted)) {
            throw new RuntimeException('The history context could not be written.');
        }
        if (! chmod($file, 0444) || ! chmod($destination, 0555)) {
            throw new RuntimeException('The history context could not be sealed read-only.');
        }

        return $file;
    }

    private function regularDirectory(string $path): string
    {
        $real = realpath($path);
        if ($real === false || is_link($path) || ! is_dir($path)) {
            throw new RuntimeException('The history context directory is unavailable or unsafe.');
        }

        return $real;
    }
}
