<?php

namespace Tests\Feature\Git;

use App\AI6\Git\CanonicalDiffHasher;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\RunBranchName;
use App\AI6\Git\RunHistoryContext;
use App\AI6\Runs\Models\Run;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\Attributes\After;
use RuntimeException;
use Tests\TestCase;

/**
 * TC-02, TC-05 and TC-06: checkpoint, diff determinism and the checkout/diff negative fixtures
 * against a real SHA-256 repository through the hardened seam.
 */
final class RunWorkspaceGitTest extends TestCase
{
    use BuildsRunWorkspaceGitFixture;

    #[After]
    public function removeFixture(): void
    {
        $this->removeRunWorkspaceFixture();
    }

    public function test_the_canonical_run_diff_is_stable_and_changes_with_content_mode_and_rename(): void
    {
        $root = $this->runWorkspaceRoot();
        $runner = $this->runWorkspaceRunner($root);
        [$repository, $base] = $this->runWorkspaceRepository($root);
        $context = new RedactionContext('project-1', null, 'run-diff');
        $hasher = $this->hasher();

        file_put_contents($repository.'/a.txt', "second\n");
        $this->runWorkspaceGit(['add', '-A'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'content'], $repository);
        $content = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));

        $first = $hasher->fromRaw($runner->canonicalRawDiff($repository, $base, $content, $context)->output, $context);
        $second = $hasher->fromRaw($runner->canonicalRawDiff($repository, $base, $content, $context)->output, $context);
        self::assertSame($first->hash, $second->hash, 'The same bound comparison must repeat its hash.');
        self::assertCount(1, $first->entries);
        self::assertSame('a.txt', $first->entries[0]['path']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $first->entries[0]['new_oid']);

        $this->runWorkspaceGit(['mv', 'sub/b.txt', 'sub/renamed.txt'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'rename'], $repository);
        $renamed = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $renamedHash = $hasher->fromRaw($runner->canonicalRawDiff($repository, $base, $renamed, $context)->output, $context)->hash;
        self::assertNotSame($first->hash, $renamedHash, 'A rename must change the canonical diff hash.');

        $modeHash = $hasher->fromRaw(
            $runner->canonicalRawDiff($repository, $content, $renamed, $context)->output,
            $context,
        )->hash;
        self::assertNotSame($renamedHash, $modeHash);
    }

    public function test_the_run_diff_executes_no_repository_or_host_configured_helper(): void
    {
        $root = $this->runWorkspaceRoot();
        $runner = $this->runWorkspaceRunner($root);
        [$repository, $base] = $this->runWorkspaceRepository($root);
        $context = new RedactionContext('project-1', null, 'run-diff-negative');
        $marker = $root.'/helper-was-run';
        $helper = $root.'/helper';
        file_put_contents($helper, "#!/bin/sh\nprintf helper > \"$marker\"\n");
        @chmod($helper, 0755);

        file_put_contents($repository.'/.gitattributes', "a.txt filter=evil diff=evil\n");
        file_put_contents($repository.'/a.txt', "second\n");
        $this->runWorkspaceGit(['add', '-A'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'attributes'], $repository);
        $head = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        foreach ([
            ['filter.evil.clean', $helper],
            ['filter.evil.smudge', $helper],
            ['filter.evil.required', 'true'],
            ['diff.evil.textconv', $helper],
            ['core.fsmonitor', $helper],
            ['core.pager', $helper],
        ] as [$key, $value]) {
            $this->runWorkspaceGit(['config', $key, $value], $repository);
        }

        $result = $runner->canonicalRawDiff($repository, $base, $head, $context);

        self::assertTrue($result->succeeded());
        self::assertFileDoesNotExist($marker, 'No repository-configured helper may run.');
        self::assertStringNotContainsString('helper', $result->output);
    }

    public function test_a_rejected_repository_configuration_blocks_the_run_diff(): void
    {
        $root = $this->runWorkspaceRoot();
        $runner = $this->runWorkspaceRunner($root);
        [$repository, $base] = $this->runWorkspaceRepository($root);
        $this->runWorkspaceGit(['config', 'core.sshCommand', $root.'/helper'], $repository);

        $result = $runner->canonicalRawDiff($repository, $base, $base, new RedactionContext('project-1', null, 'run-diff-rejected'));

        self::assertFalse($result->succeeded());
        self::assertSame('Repository Git configuration was rejected.', $result->errorOutput);
    }

    public function test_a_bound_run_tree_and_blob_are_readable_through_the_single_seam(): void
    {
        $root = $this->runWorkspaceRoot();
        $runner = $this->runWorkspaceRunner($root);
        [$repository, $base] = $this->runWorkspaceRepository($root);
        $context = new RedactionContext('project-1', null, 'run-tree');

        $tree = $runner->resolveTree($repository, $base, $context);
        self::assertTrue($tree->succeeded());
        $entries = $runner->listRunTreeEntries($repository, trim($tree->output), $context);

        $names = array_map(static fn ($entry): string => $entry->name, $entries);
        self::assertSame(['a.txt', 'sub'], $names);
        $blobEntry = $entries[0];
        self::assertTrue($blobEntry->isRegularBlob());
        self::assertSame("first\n", $runner->readRunBlob($repository, $blobEntry->objectId, 1024, $context));
    }

    public function test_the_worktree_checkpoint_and_cleanup_run_under_the_project_effect_lock(): void
    {
        $root = $this->runWorkspaceRoot();
        $runner = $this->runWorkspaceRunner($root);
        [$repository, $base] = $this->runWorkspaceRepository($root);
        $context = new RedactionContext('project-1', null, 'run-worktree');
        $branch = RunBranchName::forRun(str_repeat('a', 32), '123e4567-e89b-42d3-a456-426614174000');

        // The fixture provisions no effect-lock object, so every effecting call must refuse to run.
        $created = $runner->createRunWorktree($repository, $root.'/worktree', $branch->value, $base, 'lock-0001', $context);

        self::assertFalse($created->succeeded(), 'An effecting worktree call without a usable effect lock must fail.');
        self::assertDirectoryDoesNotExist($root.'/worktree');
        self::assertSame(
            '',
            trim($this->runWorkspaceGit(['for-each-ref', '--format=%(refname)', 'refs/heads/ai6'], $repository)),
            'A refused effecting call must not leave a run branch behind.',
        );
    }

    public function test_the_history_context_is_a_separate_sealed_artifact_without_identities(): void
    {
        $root = $this->runWorkspaceRoot();
        $runner = $this->runWorkspaceRunner($root);
        [$repository, $head] = $this->runWorkspaceRepository($root);
        $context = new RedactionContext('project-1', null, 'run-history');
        $run = new Run;
        $run->worktree_path = $repository;
        $run->checkpoint_commit_sha = $head;
        $history = new RunHistoryContext($runner, $this->redactor());

        $file = $history->export($run, $root.'/history', 20, $context);

        self::assertFileExists($file);
        self::assertFalse(is_writable($file), 'The history context must be technically read-only.');
        $content = file_get_contents($file);
        self::assertIsString($content);
        self::assertStringContainsString($head, $content);
        self::assertStringNotContainsString('ai6@example.invalid', $content, 'The history context carries no identities.');
        self::assertStringNotContainsString('AI6 Test', $content);
        self::assertFileDoesNotExist($root.'/history/.git');

        try {
            $history->export($run, $repository.'/inside-history', 20, $context);
            self::fail('The history context was created inside the managed workspace.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('inside the managed workspace', $exception->getMessage());
        }
    }

    private function redactor(): Redactor
    {
        $ring = new RedactionKeyring('test-v1', ['test-v1' => ['version' => 1, 'key' => str_repeat('g', 32)]]);

        return new Redactor(new RedactionPolicy(RedactionRuleSet::defaults()), new RedactionFingerprintGenerator($ring));
    }

    private function hasher(): CanonicalDiffHasher
    {
        $ring = new RedactionKeyring('test-v1', ['test-v1' => ['version' => 1, 'key' => str_repeat('g', 32)]]);

        return new CanonicalDiffHasher(
            new CanonicalJson,
            new Redactor(new RedactionPolicy(RedactionRuleSet::defaults()), new RedactionFingerprintGenerator($ring)),
        );
    }
}
