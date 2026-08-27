<?php

namespace Tests\Feature\Git;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Auth\Models\User;
use App\AI6\Git\CanonicalDiffHasher;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\ReviewSubject;
use App\AI6\Git\ReviewSubjectException;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\Git\ReviewSubjectNormalizer;
use App\AI6\Git\ReviewSubjectReference;
use App\AI6\Git\ReviewSubjectVerifier;
use App\AI6\Projects\Models\Project;
use App\AI6\Reviews\ReviewerSlotFactory;
use App\AI6\Runs\ApprovalLimits;
use App\AI6\Runs\ApprovalSelection;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunState;
use App\AI6\Runs\RunType;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Runs\BuildsImplementationTurnFixture;
use Tests\Feature\Runs\BuildsReviewOnlyRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-01 and TC-03 of AI6-040 against a real managed SHA-256 clone: every bound
 * source kind normalizes with its own tree and diff binding, and every refused
 * base, rewrite or unrelated history blocks with its own reason.
 */
final class ReviewSubjectBindingTest extends TicketUiTestCase
{
    use BuildsImplementationTurnFixture;
    use BuildsReviewOnlyRunFixture;
    use BuildsRunWorkspaceGitFixture;

    protected function approvalSelection(?User $attentionUser = null): ApprovalSelection
    {
        if (! $this->reviewOnlySubject instanceof ReviewSubject) {
            return $this->implementationSelection($attentionUser);
        }

        return $this->reviewOnlySelection($attentionUser);
    }

    public function test_each_bound_source_kind_normalizes_into_its_own_checkpoint_binding(): void
    {
        $this->requiresPosixEffectRuntime();
        foreach ([
            ReviewSubjectKind::MANAGED_BRANCH,
            ReviewSubjectKind::COMMIT_RANGE,
            ReviewSubjectKind::SINGLE_COMMIT,
        ] as $index => $kind) {
            $prepared = $this->preparedReviewOnlyRun('AI6-040-SRC-'.$index, $kind);
            $run = $this->normalize($prepared);

            self::assertSame($kind->value, $run->review_subject_kind);
            self::assertSame($prepared['base'], $run->review_subject_base_sha);
            self::assertSame($prepared['source'], $run->review_subject_source_sha);
            self::assertSame(
                $this->trimmedGit(['rev-parse', $prepared['source'].'^{tree}'], $prepared['repository']),
                $run->checkpoint_tree_sha,
                'The checkpoint tree must be the tree of the bound source commit.',
            );
            self::assertSame(
                $this->expectedDiffHash($prepared['repository'], $prepared['base'], $prepared['source'], $run),
                $run->checkpoint_diff_hash,
                'The bound diff hash must be the canonical base-to-source diff.',
            );
            self::assertNull($run->run_branch, 'Source normalization never creates a run branch.');
            self::assertIsString($run->worktree_path);
            self::assertFileExists($run->worktree_path.'/app/Example.php');
            self::assertStringContainsString('reviewed change', (string) file_get_contents($run->worktree_path.'/app/Example.php'));
            self::assertFileDoesNotExist($run->worktree_path.'/.git');
        }
    }

    public function test_a_stored_patch_and_checkpoint_source_must_match_its_recorded_run_binding(): void
    {
        $this->requiresPosixEffectRuntime();
        foreach ([ReviewSubjectKind::VALIDATED_PATCH, ReviewSubjectKind::CHECKPOINT] as $index => $kind) {
            $prepared = $this->preparedReviewOnlyRunForStoredKind('AI6-040-STORE-'.$index, $kind);
            $run = $this->normalize($prepared);
            self::assertSame($kind->value, $run->review_subject_kind);
            self::assertSame($prepared['source'], $run->review_subject_source_sha);

            $context = $this->context($prepared['project']);
            $verifier = $this->app->make(ReviewSubjectVerifier::class);
            $references = $this->app->make(ReviewSubjectReference::class);
            $mismatch = $references->encode(new ReviewSubject(
                $kind,
                $prepared['base'],
                $prepared['source'],
                sourceRunId: $prepared['sourceRunId'],
                expectedTreeOid: str_repeat('b', 64),
                expectedDiffHash: $prepared['sourceDiffHash'],
            ));
            $expected = $kind === ReviewSubjectKind::VALIDATED_PATCH
                ? 'validated_patch_binding_mismatch'
                : 'checkpoint_binding_mismatch';

            try {
                $verifier->verifyApproval($prepared['project'], $prepared['base'], $mismatch, $context);
                self::fail('A stored source with a foreign tree binding was accepted.');
            } catch (ReviewSubjectException $exception) {
                self::assertSame($expected, $exception->reason);
            }
        }
    }

    public function test_an_invalid_base_is_refused_for_every_bound_source_kind(): void
    {
        $prepared = $this->preparedReviewOnlyRun('AI6-040-BASE');
        $verifier = $this->app->make(ReviewSubjectVerifier::class);
        $references = $this->app->make(ReviewSubjectReference::class);
        $context = $this->context($prepared['project']);

        foreach ([
            ReviewSubjectKind::MANAGED_BRANCH,
            ReviewSubjectKind::COMMIT_RANGE,
            ReviewSubjectKind::SINGLE_COMMIT,
        ] as $kind) {
            $reference = $references->encode($this->reviewSubjectFor($kind, $prepared['base'], $prepared['source']));
            $verifier->verifyApproval($prepared['project'], $prepared['base'], $reference, $context);

            try {
                $verifier->verifyApproval($prepared['project'], str_repeat('9', 64), $reference, $context);
                self::fail('A source bound to a foreign base was accepted for '.$kind->value.'.');
            } catch (ReviewSubjectException $exception) {
                self::assertSame('source_base_mismatch', $exception->reason);
            }
        }
    }

    public function test_a_merge_commit_bound_as_a_single_commit_blocks_with_its_own_reason(): void
    {
        $prepared = $this->preparedReviewOnlyRun('AI6-040-MERGE');
        $repository = $prepared['repository'];
        $this->managedGit(['checkout', '-b', 'side', $prepared['base']], $repository);
        $this->managedReviewCommit($repository, 'app/Side.php', "<?php\n\n// side\n", 'side change');
        $this->managedGit(['checkout', 'main'], $repository);
        $this->managedGit(['merge', '--no-ff', '-m', 'merge side', 'side'], $repository);
        $merge = $this->trimmedGit(['rev-parse', 'HEAD'], $repository);

        $reference = $this->app->make(ReviewSubjectReference::class)->encode(
            new ReviewSubject(ReviewSubjectKind::SINGLE_COMMIT, $prepared['base'], $merge),
        );

        try {
            $this->app->make(ReviewSubjectVerifier::class)
                ->verifyApproval($prepared['project'], $prepared['base'], $reference, $this->context($prepared['project']));
            self::fail('A merge commit was accepted as a single-commit source.');
        } catch (ReviewSubjectException $exception) {
            self::assertSame('single_commit_merge_forbidden', $exception->reason);
        }
    }

    public function test_a_rewrite_and_an_unrelated_history_block_with_distinguishable_reasons(): void
    {
        $prepared = $this->preparedReviewOnlyRun('AI6-040-BASE-PROOF');
        $repository = $prepared['repository'];
        $verifier = $this->app->make(ReviewSubjectVerifier::class);
        $references = $this->app->make(ReviewSubjectReference::class);
        $context = $this->context($prepared['project']);

        // A regular fast-forward descendant of the approved base is accepted.
        $branchReference = $references->encode(
            new ReviewSubject(ReviewSubjectKind::MANAGED_BRANCH, $prepared['base'], $prepared['source'], 'refs/heads/main'),
        );
        $verifier->verifyApproval($prepared['project'], $prepared['base'], $branchReference, $context);

        // An unrelated history shares no ancestor with the approved base.
        $this->managedGit(['checkout', '--orphan', 'unrelated'], $repository);
        $this->managedGit(['rm', '-rf', '--cached', '.'], $repository);
        $unrelated = $this->managedReviewCommit($repository, 'app/Unrelated.php', "<?php\n\n// unrelated\n", 'unrelated root');
        $this->managedGit(['checkout', 'main'], $repository);
        $unrelatedReference = $references->encode(
            new ReviewSubject(ReviewSubjectKind::COMMIT_RANGE, $prepared['base'], $unrelated),
        );

        try {
            $verifier->verifyApproval($prepared['project'], $prepared['base'], $unrelatedReference, $context);
            self::fail('An unrelated history was accepted as a review source.');
        } catch (ReviewSubjectException $exception) {
            self::assertSame('source_history_unrelated', $exception->reason);
        }

        // A rewrite leaves the bound ref on a different commit than the binding.
        $this->managedGit(['commit', '--amend', '-m', 'rewritten reviewed change'], $repository);
        self::assertNotSame($prepared['source'], $this->trimmedGit(['rev-parse', 'refs/heads/main'], $repository));

        try {
            $verifier->verifyApproval($prepared['project'], $prepared['base'], $branchReference, $context);
            self::fail('A rewritten managed branch was accepted as a review source.');
        } catch (ReviewSubjectException $exception) {
            self::assertSame('managed_branch_drift', $exception->reason);
        }
    }

    /**
     * @param  array{run: Run, project: Project, repository: string, base: string, source: string, paths: ManagedProjectPath}  $prepared
     */
    private function normalize(array $prepared): Run
    {
        return $this->app->make(ReviewSubjectNormalizer::class)->normalize(
            $prepared['run'],
            (string) $prepared['project']->project_identifier,
            $this->context($prepared['project']),
        )->refresh();
    }

    private function context(Project $project): RedactionContext
    {
        return new RedactionContext((string) $project->id, null, 'review-subject-binding-test');
    }

    /** @param list<string> $arguments */
    private function trimmedGit(array $arguments, string $repository): string
    {
        return trim($this->managedGit($arguments, $repository));
    }

    private function expectedDiffHash(string $repository, string $base, string $source, Run $run): string
    {
        $raw = $this->managedGit(
            ['diff', '--no-ext-diff', '--no-textconv', '--no-renames', '--raw', '-z', '--no-abbrev', $base, $source],
            $repository,
        );

        return $this->app->make(CanonicalDiffHasher::class)
            ->fromRaw($raw, new RedactionContext((string) $run->project_id, $run->id, 'expected-diff'))->hash;
    }

    /**
     * A review-only run whose source is the stored binding of an earlier
     * implementation run in the same project.
     *
     * @return array{run: Run, project: Project, repository: string, base: string, source: string, sourceRunId: string, sourceDiffHash: string, paths: ManagedProjectPath}
     */
    private function preparedReviewOnlyRunForStoredKind(string $ticketId, ReviewSubjectKind $kind): array
    {
        $this->reviewOnlySubject = null;
        $managed = $this->prepareManagedReviewProject($ticketId);
        $repository = $managed['repository'];
        $base = $managed['base'];
        $source = $managed['source'];

        // The implementation run whose recorded checkpoint the review source
        // refers to is claimed through the same real seam.
        $implementationFixture = $this->completedApproval($ticketId.'-SEED', $managed['project']->refresh(), $managed['operator']);
        $sourceRun = $this->finalizedRun($implementationFixture, $base);
        DB::table('jobs')->delete();
        self::assertSame(RunType::IMPLEMENTATION, $sourceRun->run_type);

        $treeOid = $this->trimmedGit(['rev-parse', $source.'^{tree}'], $repository);
        $diffHash = $this->expectedDiffHash($repository, $base, $source, $sourceRun);
        $sourceRun = $this->app->make(RunOrchestrator::class)
            ->bindCheckpoint($sourceRun, $sourceRun->version, $source, $treeOid, $diffHash);
        if ($kind === ReviewSubjectKind::VALIDATED_PATCH) {
            RunArtifact::query()->create([
                'id' => (string) Str::uuid(),
                'run_id' => $sourceRun->id,
                'kind' => 'implementation_summary',
                'redacted_metadata' => [],
                'digest' => hash('sha256', 'summary-'.$sourceRun->id),
                'size_bytes' => 2,
                'sequence' => 1,
                'storage_reference' => 'test://implementation-summary/'.$sourceRun->id,
                'expires_at' => now()->addDay(),
            ]);
        }
        // The seed run is finished for the fixture so the project lock frees up
        // for the review-only claim under test.
        self::assertSame(1, DB::table('runs')->where('id', $sourceRun->id)->update([
            'state' => RunState::COMPLETED->value,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]));
        DB::table('projects')->where('id', $sourceRun->project_id)->update(['active_run_id' => null]);

        $this->reviewOnlySubject = new ReviewSubject(
            $kind,
            $base,
            $source,
            sourceRunId: $sourceRun->id,
            expectedTreeOid: $treeOid,
            expectedDiffHash: $diffHash,
        );

        return [
            'run' => $this->claimReviewOnlyRun($ticketId, $managed),
            'project' => $managed['project']->refresh(),
            'repository' => $repository,
            'base' => $base,
            'source' => $source,
            'sourceRunId' => $sourceRun->id,
            'sourceDiffHash' => $diffHash,
            'paths' => $managed['paths'],
        ];
    }

    private function implementationSelection(?User $attentionUser): ApprovalSelection
    {
        $profiles = $this->app->make(AgentProfileRegistry::class);

        return new ApprovalSelection(
            $profiles->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium'),
            $this->app->make(ReviewerSlotFactory::class)->fromArray([[
                'id' => (string) Str::uuid(),
                'profile' => 'fake',
                'model' => 'fake-model',
                'effort' => 'high',
                'prompt_profile' => 'security',
            ]]),
            ApprovalLimits::fromConfiguredValues(
                config('ai6.project_config.server_defaults.limits'),
                $this->app->make(AgentInputLimits::class),
            ),
            $attentionUser?->getKey(),
            'manual',
        );
    }
}
