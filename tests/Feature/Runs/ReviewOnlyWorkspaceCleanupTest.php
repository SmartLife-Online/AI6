<?php

namespace Tests\Feature\Runs;

use App\AI6\Auth\Models\User;
use App\AI6\Git\ReviewSubjectNormalizer;
use App\AI6\Git\RunWorkspaceLifecycle;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\ExecutionStepType;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunState;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Git\BuildsRunWorkspaceGitFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-11 of AI6-040: the disposable review checkpoint disappears after the run,
 * a restart clears orphaned staging and export remainders, and neither the ref
 * inventory nor a Git worktree registration survives.
 */
final class ReviewOnlyWorkspaceCleanupTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;
    use BuildsReviewOnlyRunFixture;
    use BuildsRunWorkspaceGitFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            /** @param list<string> $ticketFiles */
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
    }

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        return $this->reviewOnlySelection($attentionUser);
    }

    public function test_the_bound_review_checkpoint_is_removed_and_leaves_no_ref_or_worktree_entry(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-CLEAN');
        $repository = $prepared['repository'];
        $identifier = (string) $prepared['project']->project_identifier;
        $refsBefore = $this->managedGit(['show-ref'], $repository);

        $this->assertStepSucceeded($this->executeReviewOnlyStep($prepared['run'], ExecutionStepType::REVIEW_PREPARE));
        $run = $prepared['run']->fresh();
        self::assertInstanceOf(Run::class, $run);
        $workspace = (string) $run->worktree_path;
        self::assertDirectoryExists($workspace);
        self::assertStringNotContainsString(
            '.review-stage-',
            $this->managedGit(['worktree', 'list', '--porcelain'], $repository),
            'The detached staging worktree is gone before the checkpoint is bound.',
        );

        $this->app->make(RunWorkspaceLifecycle::class)->cleanupReviewOnly(
            $run,
            $identifier,
            new RedactionContext((string) $run->project_id, $run->id, 'review-cleanup-test'),
        );

        self::assertDirectoryDoesNotExist($workspace, 'The disposable review checkpoint is removed.');
        self::assertSame($refsBefore, $this->managedGit(['show-ref'], $repository), 'The ref inventory stays byte-identical.');
        self::assertStringNotContainsString($run->id, $this->managedGit(['worktree', 'list', '--porcelain'], $repository));
    }

    public function test_a_restart_clears_orphaned_staging_and_export_remainders(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-CLEAN-RESTART');
        $repository = $prepared['repository'];
        $identifier = (string) $prepared['project']->project_identifier;
        $paths = $prepared['paths'];
        $run = $prepared['run'];

        // A crash after the export leaves the bound checkpoint behind.
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW_PREPARE));
        $run = $run->fresh() ?? $run;
        $export = (string) $run->worktree_path;
        self::assertDirectoryExists($export);

        // A crash inside the detached staging leaves both the directory and its
        // Git worktree registration behind.
        $stageName = '.review-stage-'.$run->id;
        $stage = $paths->runWorktreeRoot($identifier).DIRECTORY_SEPARATOR.$stageName;
        $this->managedGit(['worktree', 'add', '--detach', $stage, $prepared['source']], $repository);
        self::assertStringContainsString($stageName, $this->managedGit(['worktree', 'list', '--porcelain'], $repository));
        $refsWithStage = $this->managedGit(['show-ref'], $repository);

        // The run is finished, so the reconciliation owns both remainders.
        self::assertSame(1, DB::table('runs')->where('id', $run->id)->update([
            'state' => RunState::COMPLETED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));

        $removed = $this->app->make(RunWorkspaceLifecycle::class)->reconcile(
            $identifier,
            new RedactionContext((string) $run->project_id, $run->id, 'review-reconcile-test'),
        );

        self::assertContains($run->id, $removed);
        self::assertContains($stageName, $removed);
        self::assertDirectoryDoesNotExist($export);
        self::assertDirectoryDoesNotExist($stage);
        self::assertStringNotContainsString($stageName, $this->managedGit(['worktree', 'list', '--porcelain'], $repository));
        self::assertSame($refsWithStage, $this->managedGit(['show-ref'], $repository), 'The reconciliation touches no ref.');
    }

    public function test_the_review_only_reconciliation_never_deletes_a_foreign_ref(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-CLEAN-REF');
        $repository = $prepared['repository'];
        $identifier = (string) $prepared['project']->project_identifier;
        $run = $prepared['run'];
        $this->assertStepSucceeded($this->executeReviewOnlyStep($run, ExecutionStepType::REVIEW_PREPARE));
        $run = $run->fresh() ?? $run;

        // A ref that only an implementation run would ever own. The review-only
        // reconciliation must not run the branch-deleting discard path for it.
        $branch = 'refs/heads/ai6/runs/'.$identifier.'/'.$run->id;
        $this->managedGit(['update-ref', $branch, $prepared['base']], $repository);
        self::assertSame(1, DB::table('runs')->where('id', $run->id)->update([
            'state' => RunState::COMPLETED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));

        $this->app->make(RunWorkspaceLifecycle::class)->reconcile(
            $identifier,
            new RedactionContext((string) $run->project_id, $run->id, 'review-reconcile-ref-test'),
        );

        self::assertStringContainsString(
            $branch,
            $this->managedGit(['show-ref'], $repository),
            'A review-only workspace never triggers the branch-deleting discard path.',
        );
    }

    public function test_a_second_normalization_of_a_bound_run_creates_no_second_workspace(): void
    {
        $this->requiresPosixEffectRuntime();
        $prepared = $this->preparedReviewOnlyRun('AI6-040-CLEAN-IDEMPOTENT');
        $identifier = (string) $prepared['project']->project_identifier;
        $context = new RedactionContext((string) $prepared['project']->id, $prepared['run']->id, 'review-idempotent-test');
        $normalizer = $this->app->make(ReviewSubjectNormalizer::class);

        $first = $normalizer->normalize($prepared['run'], $identifier, $context)->refresh();
        $second = $normalizer->normalize($first, $identifier, $context)->refresh();

        self::assertSame($first->worktree_path, $second->worktree_path);
        self::assertSame($first->checkpoint_tree_sha, $second->checkpoint_tree_sha);
        self::assertSame(
            [basename((string) $first->worktree_path)],
            $prepared['paths']->runWorktreeEntries($identifier),
            'Exactly one bound review workspace exists.',
        );
    }
}
