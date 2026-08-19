<?php

namespace Tests\Feature\Git;

use App\AI6\Git\CanonicalDiffHasher;
use App\AI6\Git\CanonicalJson;
use App\AI6\Git\ControlOperationTerminalConflict;
use App\AI6\Git\TicketFileDeltaProof;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use PHPUnit\Framework\Attributes\After;
use Tests\TestCase;

/**
 * AC-09: the tree proof of the amendment saga over the real Git object store.
 *
 * A commit that differs from its parent in exactly the bound ticket file
 * passes; a commit touching any other tree entry is a named terminal conflict
 * and never a rebase.
 */
final class TicketFileDeltaProofTest extends TestCase
{
    use BuildsRunWorkspaceGitFixture;

    #[After]
    public function removeFixture(): void
    {
        $this->removeRunWorkspaceFixture();
    }

    private function proof(): TicketFileDeltaProof
    {
        $ring = new RedactionKeyring('test-v1', ['test-v1' => ['version' => 1, 'key' => str_repeat('g', 32)]]);
        $redactor = new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator($ring),
        );

        return new TicketFileDeltaProof(
            $this->runWorkspaceRunner($this->runWorkspaceRoot()),
            new CanonicalDiffHasher(new CanonicalJson, $redactor),
        );
    }

    public function test_a_single_ticket_file_change_passes_and_any_other_delta_blocks(): void
    {
        $root = $this->runWorkspaceRoot();
        $proof = $this->proof();
        [$repository, $parent] = $this->runWorkspaceRepository($root);
        self::assertTrue(mkdir($repository.'/tickets', 0700));
        self::assertNotFalse(file_put_contents($repository.'/tickets/AI6-020.md', "alt\n"));
        $this->runWorkspaceGit(['add', '-A'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'ticket base'], $repository);
        $parent = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $context = new RedactionContext('proof', 'proof', 'delta-proof');

        // Exactly the ticket file changed: the proof passes before and after.
        self::assertNotFalse(file_put_contents($repository.'/tickets/AI6-020.md', "neu\n"));
        $this->runWorkspaceGit(['add', '-A'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'amendment'], $repository);
        $amendment = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        $proof->assertOnlyPathChanged($repository, $parent, $amendment, 'tickets/AI6-020.md', $context);

        // A second changed tree entry is unexpected drift and blocks (AC-13).
        self::assertNotFalse(file_put_contents($repository.'/a.txt', "manipuliert\n"));
        $this->runWorkspaceGit(['add', '-A'], $repository);
        $this->runWorkspaceGit(['commit', '-m', 'drift'], $repository);
        $drifted = trim($this->runWorkspaceGit(['rev-parse', 'HEAD'], $repository));
        try {
            $proof->assertOnlyPathChanged($repository, $parent, $drifted, 'tickets/AI6-020.md', $context);
            self::fail('A commit touching more than the ticket file must block.');
        } catch (ControlOperationTerminalConflict $conflict) {
            self::assertSame('amendment_tree_changed', $conflict->conflict);
        }

        // A commit changing only a foreign path never proves the ticket delta.
        try {
            $proof->assertOnlyPathChanged($repository, $amendment, $drifted, 'tickets/AI6-020.md', $context);
            self::fail('A foreign-path delta must block.');
        } catch (ControlOperationTerminalConflict $conflict) {
            self::assertSame('amendment_tree_changed', $conflict->conflict);
        }
    }
}
