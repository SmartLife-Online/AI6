<?php

namespace Tests\Feature\Git;

use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\IsolatedTreeExport;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\ProjectEffectLockName;
use App\AI6\Shared\Redaction\RedactionContext;
use Symfony\Component\Process\Process;

final class ReviewOnlyCheckpointIsolationTest extends ControlOperationTestCase
{
    public function test_detached_staging_exports_no_git_metadata_and_preserves_ref_inventory(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRealWorkerRuntime($project);
        $this->app->forgetInstance(HardenedGitRunner::class);
        $paths = $this->app->make(ManagedProjectPath::class);
        $identifier = (string) $project->refresh()->project_identifier;
        $repository = $paths->repositoryDirectory($identifier);
        self::assertTrue(mkdir($repository, 0700, true));
        $this->git(['init', '--object-format=sha256', '--initial-branch=main'], $repository);
        self::assertNotFalse(file_put_contents($repository.'/review.txt', "bound\n"));
        $this->git(['add', '--all'], $repository);
        $this->git(['commit', '-m', 'bound review source'], $repository);
        $source = trim($this->git(['rev-parse', 'HEAD'], $repository));

        $runner = $this->app->make(HardenedGitRunner::class);
        $context = new RedactionContext((string) $project->id, null, 'review-checkpoint-isolation');
        $before = $runner->refs($repository, $context);
        $stageName = '.review-stage-123e4567-e89b-42d3-a456-426614174000';
        $stage = $paths->runWorktreeRoot($identifier).DIRECTORY_SEPARATOR.$stageName;
        $runId = '123e4567-e89b-42d3-a456-426614174000';
        $export = $paths->runWorktreeDirectory($identifier, $runId);
        $lock = $this->app->make(ProjectEffectLockName::class)->forProject($identifier);

        self::assertTrue($runner->createDetachedReviewWorktree($repository, $stage, $source, $lock, $context)->succeeded());
        $this->app->make(IsolatedTreeExport::class)->export($stage, $export);
        $runner->discardDetachedReviewWorktree($repository, $stage, $lock, $context);
        $paths->removeRunWorktreeDirectory($identifier, $stageName);

        self::assertFileExists($export.'/review.txt');
        self::assertFileDoesNotExist($export.'/.git');
        self::assertFalse(is_writable($export.'/review.txt'));
        self::assertSame($before, $runner->refs($repository, $context));
        self::assertStringNotContainsString($stage, $this->git(['worktree', 'list', '--porcelain'], $repository));

        $paths->removeRunWorktreeDirectory($identifier, $runId);
        self::assertDirectoryDoesNotExist($export);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, string $repository): string
    {
        $process = new Process(['git', ...$arguments], $repository, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'AI6 Review Test',
            'GIT_AUTHOR_EMAIL' => 'review@example.invalid',
            'GIT_COMMITTER_NAME' => 'AI6 Review Test',
            'GIT_COMMITTER_EMAIL' => 'review@example.invalid',
        ]);
        $process->mustRun();

        return $process->getOutput();
    }
}
