<?php

namespace Tests\Feature\Git;

use App\AI6\Git\CandidateProvenancePreflight;
use App\AI6\Git\CanonicalDiff;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\PublishCandidateException;
use App\AI6\Git\PublishCandidateService;
use App\AI6\Git\RunBranchName;
use App\AI6\Git\RunTreeService;
use App\AI6\Projects\Models\Project;
use App\AI6\Runs\ApprovalSnapshotFactory;
use App\AI6\Runs\InstructionCandidateCollector;
use App\AI6\Runs\InstructionCandidateSource;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\TicketApproval;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionContext;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Runs\BuildsFinalizedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class PublishCandidateTest extends TicketUiTestCase
{
    use BuildsFinalizedRunFixture;
    use BuildsRunWorkspaceGitFixture;

    #[After]
    public function removeCandidateFixture(): void
    {
        $this->removeRunWorkspaceFixture();
    }

    public function test_the_candidate_is_deterministic_idempotently_bound_and_creates_no_commit_or_ref(): void
    {
        $prepared = $this->preparedCandidate('AI6-027-CANDIDATE', "second\n");
        $service = $this->app->make(PublishCandidateService::class);
        $refs = $this->runWorkspaceGit(['show-ref'], $prepared['repository']);
        $commits = trim($this->runWorkspaceGit(['rev-list', '--all', '--count'], $prepared['repository']));

        $first = $service->prospect($prepared['run']);
        $second = $service->prospect($prepared['run']);

        self::assertEquals($first, $second);
        self::assertSame($prepared['base'], $first->baseSha);
        self::assertSame($refs, $this->runWorkspaceGit(['show-ref'], $prepared['repository']));
        self::assertSame($commits, trim($this->runWorkspaceGit(['rev-list', '--all', '--count'], $prepared['repository'])));
        $candidateDiff = $this->app->make(RunTreeService::class)->diff(
            $prepared['run'],
            $prepared['base'],
            $first->treeOid,
            new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'candidate-test'),
        );
        self::assertSame(['a.txt'], array_column($candidateDiff->entries, 'path'));

        $bound = $service->bind($prepared['run'], $first);
        self::assertSame($first->treeOid, $bound->candidate_tree_sha);
        self::assertSame($first->diffHash, $bound->candidate_diff_hash);
        self::assertSame($prepared['checkpoint'], $bound->candidate_checkpoint_commit_sha);
        $approval = TicketApproval::query()->findOrFail($bound->ticket_approval_id);
        self::assertSame($approval->ticket_contract_sha256, $bound->candidate_ticket_contract_sha256);
        self::assertSame($approval->approval_snapshot_hash, $bound->candidate_approval_snapshot_hash);
        $version = $bound->version;
        self::assertSame($version, $service->bind($bound, $first)->version, 'A repeated identical binding is a no-op.');
    }

    public function test_the_candidate_preflight_rejects_a_secret_in_the_actual_blob(): void
    {
        $prepared = $this->preparedCandidate('AI6-027-SECRET', "secret=verybadvalue\n");

        $this->expectException(PublishCandidateException::class);
        $this->expectExceptionMessage('candidate_secret_detected');
        $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
    }

    #[DataProvider('unsupportedCandidateBlobProvider')]
    public function test_the_candidate_preflight_names_unsupported_blob_content(string $content, string $reason): void
    {
        $prepared = $this->preparedCandidate('AI6-027-BLOB-'.strtoupper(substr($reason, -4)), $content);

        try {
            $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
            self::fail('The unsupported candidate blob was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertNull($prepared['run']->fresh()->candidate_tree_sha);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsupportedCandidateBlobProvider(): iterable
    {
        yield 'larger than the provenance inspection limit' => [str_repeat('x', 1048577), 'candidate_blob_too_large'];
        yield 'non UTF-8 binary modification' => ["\xFF\xFE\x00binary", 'candidate_blob_not_utf8'];
    }

    public function test_the_candidate_preflight_rejects_a_symlink_entry(): void
    {
        $prepared = $this->preparedCandidate('AI6-027-SYMLINK', "target\n", '120000');

        $this->expectException(PublishCandidateException::class);
        $this->expectExceptionMessage('candidate_symlink_forbidden');
        $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
    }

    #[DataProvider('forbiddenCandidateEntryProvider')]
    public function test_the_candidate_preflight_rejects_other_forbidden_entries(
        string $suffix,
        ?string $mode,
        bool $approveScope,
        string $reason,
    ): void {
        $prepared = $this->preparedCandidate('AI6-027-'.$suffix, "entry\n", $mode, $approveScope);

        try {
            if ($mode === '160000') {
                $context = new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'candidate-gitlink');
                $checkpoint = $this->app->make(RunTreeService::class)->diff(
                    $prepared['run'], $prepared['base'], $prepared['checkpoint'], $context,
                );
                $this->app->make(CandidateProvenancePreflight::class)->assertSafe(
                    $prepared['run'], (string) $prepared['run']->checkpoint_tree_sha, $checkpoint, $checkpoint->entries,
                );
            } else {
                $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
            }
            self::fail('The forbidden candidate entry was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame($reason, $exception->reason);
        }
        self::assertNull($prepared['run']->fresh()->candidate_tree_sha);
    }

    /** @return iterable<string, array{string, ?string, bool, string}> */
    public static function forbiddenCandidateEntryProvider(): iterable
    {
        yield 'gitlink' => ['GITLINK', '160000', true, 'candidate_gitlink_forbidden'];
        yield 'outside effective scope' => ['SCOPE', null, false, 'candidate_path_outside_effective_scope'];
    }

    public function test_the_candidate_preflight_rejects_an_unexpected_mode_and_a_change_outside_the_checkpoint(): void
    {
        $prepared = $this->preparedCandidate('AI6-027-PREFLIGHT', "entry\n");
        $service = $this->app->make(PublishCandidateService::class);
        $candidate = $service->prospect($prepared['run']);
        $context = new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'candidate-negative');
        $diff = $this->app->make(RunTreeService::class)->diff(
            $prepared['run'], $prepared['base'], $candidate->treeOid, $context,
        );
        $entry = $diff->entries[0];
        $entry['new_mode'] = '100600';
        $unexpectedMode = new CanonicalDiff([$entry], $diff->hash, $diff->redactedPresentation);

        try {
            $this->app->make(CandidateProvenancePreflight::class)->assertSafe(
                $prepared['run'], $candidate->treeOid, $unexpectedMode, $unexpectedMode->entries,
            );
            self::fail('The unexpected blob mode was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame('candidate_blob_mode_forbidden', $exception->reason);
        }

        try {
            $this->app->make(CandidateProvenancePreflight::class)->assertSafe(
                $prepared['run'],
                $candidate->treeOid,
                $diff,
                [],
            );
            self::fail('A candidate change outside the checkpoint was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame('candidate_change_not_from_checkpoint', $exception->reason);
        }
        self::assertNull($prepared['run']->fresh()->candidate_tree_sha);
    }

    #[DataProvider('workspaceDriftProvider')]
    public function test_unknown_workspace_or_run_branch_drift_prevents_the_candidate(bool $commit): void
    {
        $prepared = $this->preparedCandidate('AI6-027-DRIFT-'.($commit ? 'COMMIT' : 'TREE'), "entry\n");
        self::assertNotFalse(file_put_contents($prepared['repository'].'/drift.txt', "unexpected\n"));
        if ($commit) {
            $this->runWorkspaceGit(['add', '--all'], $prepared['repository']);
            $this->runWorkspaceGit(['commit', '-m', 'unexpected commit'], $prepared['repository']);
        }

        try {
            $this->app->make(PublishCandidateService::class)->prospect($prepared['run']);
            self::fail('The drifted workspace was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame($commit ? 'run_branch_outside_checkpoint_protocol' : 'candidate_worktree_drift', $exception->reason);
        }
        self::assertNull($prepared['run']->fresh()->candidate_tree_sha);
    }

    /** @return iterable<string, array{bool}> */
    public static function workspaceDriftProvider(): iterable
    {
        yield 'unknown worktree change' => [false];
        yield 'additional run branch commit' => [true];
    }

    public function test_the_candidate_is_reconstructed_on_the_new_run_base_without_rebasing_the_run_branch(): void
    {
        $ticketId = 'AI6-027-AMENDMENT';
        $prepared = $this->preparedCandidate($ticketId, "implementation\n");
        $repository = $prepared['repository'];
        $branchHead = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $this->runWorkspaceGit(['checkout', '--detach', $prepared['base']], $repository);
        $ticketPath = $repository.'/tickets/'.$ticketId.'.md';
        $amendedTicket = (string) file_get_contents($ticketPath)."\n## Recorded Scope\n\n- `a.txt` — automatisch aufgenommen.\n";
        self::assertNotFalse(file_put_contents($ticketPath, $amendedTicket));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'record amended ticket base'], $repository);
        $amendedBase = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $ticketBlob = trim($this->runWorkspaceGit(['rev-parse', $amendedBase.':tickets/'.$ticketId.'.md'], $repository));
        $runBranch = str_replace('refs/heads/', '', (string) $prepared['run']->run_branch);
        $this->runWorkspaceGit(['checkout', $runBranch], $repository);

        $prepared['run']->project()->update(['control_oid' => $amendedBase]);
        $ticketContract = $prepared['run']->ticket_contract_sha256
            ?? TicketApproval::query()->findOrFail($prepared['run']->ticket_approval_id)->ticket_contract_sha256;
        $run = $this->app->make(RunOrchestrator::class)->applyContractAmendment(
            $prepared['run'],
            $amendedBase,
            $ticketBlob,
            $ticketContract,
            (array) $prepared['run']->scope_snapshot,
            (string) $prepared['run']->scope_hash,
            (array) $prepared['run']->config_snapshot,
            (string) $prepared['run']->config_hash,
            (array) $prepared['run']->prompt_snapshot,
            (string) $prepared['run']->prompt_hash,
            $this->app->make(CanonicalJson::class),
            12,
        );
        // The isolated AI6-owned ticket patch is recorded once on the existing
        // run-branch history. Candidate reconstruction may discard that delta
        // only because its resulting blob equals the latest run-base ticket.
        self::assertNotFalse(file_put_contents($ticketPath, $amendedTicket));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'recheck amended ticket base'], $repository);
        $rechecked = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $checkpointDiff = $this->app->make(RunTreeService::class)->diff(
            $run,
            (string) $run->initial_run_base_sha,
            $rechecked,
            new RedactionContext((string) $run->project_id, $run->id, 'candidate-amendment-checkpoint'),
        );
        self::assertContains('tickets/'.$ticketId.'.md', array_column($checkpointDiff->entries, 'path'));
        $run = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $run,
            $run->version,
            $rechecked,
            trim($this->runWorkspaceGit(['rev-parse', $rechecked.'^{tree}'], $repository)),
            $checkpointDiff->hash,
        );
        $candidate = $this->app->make(PublishCandidateService::class)->prospect($run);

        self::assertSame($amendedBase, $candidate->baseSha);
        self::assertTrue($this->runWorkspaceGit(['merge-base', '--is-ancestor', $branchHead, $rechecked], $repository) === '');
        self::assertSame($rechecked, trim($this->runWorkspaceGit(['rev-parse', $run->run_branch], $repository)));
        self::assertSame($amendedTicket, $this->runWorkspaceGit(['show', $candidate->treeOid.':tickets/'.$ticketId.'.md'], $repository));
        $bound = $this->app->make(PublishCandidateService::class)->bind($run, $candidate);
        self::assertSame($amendedBase, $bound->candidate_base_sha);
    }

    public function test_a_discarded_ticket_delta_must_end_at_the_exact_run_base_blob(): void
    {
        $ticketId = 'AI6-027-TICKET-NEUTRAL';
        $prepared = $this->preparedCandidate($ticketId, "implementation\n");
        $repository = $prepared['repository'];
        $ticketPath = $repository.'/tickets/'.$ticketId.'.md';
        $changed = str_replace('status: in_progress', 'status: review', (string) file_get_contents($ticketPath));
        self::assertNotFalse(file_put_contents($ticketPath, $changed));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'unexpected ticket delta'], $repository);
        $checkpoint = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $context = new RedactionContext((string) $prepared['run']->project_id, $prepared['run']->id, 'candidate-ticket-neutral');
        $diff = $this->app->make(RunTreeService::class)->diff(
            $prepared['run'], (string) $prepared['run']->initial_run_base_sha, $checkpoint, $context,
        );
        self::assertContains('tickets/'.$ticketId.'.md', array_column($diff->entries, 'path'));
        $run = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $prepared['run'],
            $prepared['run']->version,
            $checkpoint,
            trim($this->runWorkspaceGit(['rev-parse', $checkpoint.'^{tree}'], $repository)),
            $diff->hash,
        );

        try {
            $this->app->make(PublishCandidateService::class)->prospect($run);
            self::fail('A non-neutral discarded ticket delta was accepted.');
        } catch (PublishCandidateException $exception) {
            self::assertSame('candidate_ticket_not_neutral', $exception->reason);
        }
        self::assertNull($run->fresh()->candidate_tree_sha);
    }

    /** @return array{run: Run, repository: string, base: string, checkpoint: string} */
    private function preparedCandidate(
        string $ticketId,
        string $content,
        ?string $entryMode = null,
        bool $approveScope = true,
    ): array {
        $root = $this->runWorkspaceRoot();
        $managed = $root.'/managed';
        $identifier = str_repeat('a', 32);
        self::assertTrue(mkdir($managed, 0700));
        self::assertTrue(mkdir($managed.'/projects', 0700));
        self::assertTrue(mkdir($managed.'/projects/'.$identifier, 0700));
        config([
            'ai6.control_operations.managed_root' => $managed,
            'ai6.control_operations.key_root' => $managed.'/deploy-keys',
            'ai6.control_operations.known_hosts_file' => $managed.'/known_hosts',
        ]);
        foreach ([ControlOperationConfiguration::class, ManagedProjectPath::class, InstructionCandidateCollector::class, ApprovalSnapshotFactory::class] as $service) {
            $this->app->forgetInstance($service);
        }
        [$repository] = $this->runWorkspaceRepository($managed.'/projects/'.$identifier);
        self::assertTrue(mkdir($repository.'/tickets', 0700));
        self::assertNotFalse(file_put_contents(
            $repository.'/tickets/'.$ticketId.'.md',
            $this->validTicketMarkdown($ticketId, 'in_progress'),
        ));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'bind in-progress ticket'], $repository);
        $base = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));

        $runner = $this->runWorkspaceRunner($root);
        $this->app->instance(HardenedGitRunner::class, $runner);
        $this->app->instance(InstructionCandidateSource::class, new class implements InstructionCandidateSource
        {
            public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
            {
                return [];
            }
        });
        $this->app->forgetInstance(ApprovalSnapshotFactory::class);
        $fixture = $this->completedApproval($ticketId);
        $run = $this->finalizedRun($fixture, $base);
        $branch = RunBranchName::forRun((string) $fixture['project']->project_identifier, $run->id);
        $this->runWorkspaceGit(['branch', '-m', $branch->shortName()], $repository);
        $run = $this->app->make(RunOrchestrator::class)->bindWorkspace($run, $run->version, $branch->value, $repository);

        self::assertNotFalse(file_put_contents($repository.'/a.txt', $content));
        $this->runWorkspaceGit(['add', '--all'], $repository);
        if ($entryMode !== null) {
            $object = $entryMode === '160000'
                ? $base
                : trim($this->runWorkspaceGit(['hash-object', '-w', 'a.txt'], $repository));
            $this->runWorkspaceGit(['update-index', '--add', '--cacheinfo', $entryMode.','.$object.',a.txt'], $repository);
        }
        $this->runWorkspaceGit(['commit', '-m', 'checkpoint'], $repository);
        $checkpoint = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        if ($entryMode === '160000') {
            self::assertTrue(unlink($repository.'/a.txt'));
        }

        foreach ([RunTreeService::class, CandidateProvenancePreflight::class, PublishCandidateService::class] as $service) {
            $this->app->forgetInstance($service);
        }
        $tree = trim($runner->resolveTree(
            $repository,
            $checkpoint,
            new RedactionContext((string) $run->project_id, $run->id, 'candidate-fixture'),
        )->output);
        $diff = $this->app->make(RunTreeService::class)->diff(
            $run,
            $base,
            $checkpoint,
            new RedactionContext((string) $run->project_id, $run->id, 'candidate-fixture'),
        );
        if ($approveScope) {
            $run = $this->app->make(RunOrchestrator::class)->applyScopeDecision(
                $run,
                'a.txt',
                true,
                null,
                1,
                $this->app->make(CanonicalJson::class),
                'auto_allow',
            );
        }
        $run = $this->app->make(RunOrchestrator::class)->bindCheckpoint(
            $run,
            $run->version,
            $checkpoint,
            $tree,
            $diff->hash,
        );

        return compact('run', 'repository', 'base', 'checkpoint');
    }
}
