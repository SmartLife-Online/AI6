<?php

namespace App\AI6\Git;

use InvalidArgumentException;

final readonly class RunBranchName
{
    private const NAMESPACE_PREFIX = 'refs/heads/ai6/runs/';

    public function __construct(public string $value)
    {
        if (preg_match('/\Arefs\/heads\/ai6\/runs\/[0-9a-f]{32}\/[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The run branch name is not canonical.');
        }
    }

    public static function forRun(string $projectIdentifier, string $runId): self
    {
        return new self(self::NAMESPACE_PREFIX.$projectIdentifier.'/'.$runId);
    }

    /**
     * The branch name relative to refs/heads/, as `git branch` and `git worktree add -b` expect it.
     */
    public function shortName(): string
    {
        return substr($this->value, strlen('refs/heads/'));
    }
}
