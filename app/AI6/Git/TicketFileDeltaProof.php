<?php

namespace App\AI6\Git;

use App\AI6\Shared\Redaction\RedactionContext;

/**
 * The tree proof of the contract-amendment saga (AC-09).
 *
 * It asserts over the real Git object store that a commit differs from its
 * parent in exactly one bound path, so every other tree entry — instruction
 * files and provider configuration included — stays byte-identical. The saga
 * runs it before and after the patch takeover; any other difference is
 * unexpected drift and blocks instead of being rebased away (AC-13).
 */
final readonly class TicketFileDeltaProof
{
    public function __construct(
        private HardenedGitRunner $git,
        private CanonicalDiffHasher $diffs,
    ) {}

    public function assertOnlyPathChanged(
        string $repository,
        string $parentOid,
        string $commitOid,
        string $relativePath,
        RedactionContext $context,
    ): void {
        $raw = $this->git->canonicalRawDiff($repository, $parentOid, $commitOid, $context);
        if (! $raw->succeeded()) {
            throw new ControlOperationRecoveryRequired('The amendment tree difference could not be inspected safely.');
        }
        $paths = array_map(
            static fn (array $entry): string => $entry['path'],
            $this->diffs->fromRaw($raw->output, $context)->entries,
        );
        if ($paths !== [$relativePath]) {
            throw new ControlOperationTerminalConflict(
                'amendment_tree_changed',
                'Der Amendment-Commit verändert mehr als die gebundene Ticketdatei.',
            );
        }
    }
}
