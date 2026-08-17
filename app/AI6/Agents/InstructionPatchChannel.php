<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Redaction\InvalidRedactionInputException;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use JsonException;

final readonly class InstructionPatchChannel
{
    public function __construct(private Redactor $redactor) {}

    /** @param list<string> $initialScope */
    public function propose(ExecutionHome $home, InstructionSnapshot $snapshot, array $initialScope, string $targetPath, string $content, RedactionContext $context): InstructionPatchProposal
    {
        $this->assertTarget($snapshot, $initialScope, $targetPath);
        try {
            $content = $this->redactor->redact($content, $context)->text;
        } catch (InvalidRedactionInputException) {
            throw new InstructionPatchException('The instruction patch content is not valid UTF-8.');
        }
        if (strlen($content) > $this->maximumBytes()) {
            throw new InstructionPatchException('The instruction patch exceeds its server limit.');
        }

        $hash = hash('sha256', $content);
        $document = json_encode(['version' => 1, 'target_path' => $targetPath, 'content_sha256' => $hash, 'content_base64' => base64_encode($content)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $path = $home->patchDirectory.'/proposal.json';
        if (file_exists($path) || file_put_contents($path, $document."\n", LOCK_EX) !== strlen($document) + 1) {
            throw new InstructionPatchException('Exactly one instruction patch may be proposed.');
        }

        return new InstructionPatchProposal($targetPath, $content, $hash);
    }

    /** @param list<string> $initialScope */
    public function readForWorker(ExecutionHome $home, InstructionSnapshot $snapshot, array $initialScope, RedactionContext $context): InstructionPatchProposal
    {
        if (config('ai6.runtime_role') !== 'worker') {
            throw new InstructionPatchException('Only the worker may consume an instruction patch proposal.');
        }
        $path = $home->patchDirectory.'/proposal.json';
        $length = is_file($path) && ! is_link($path) ? filesize($path) : false;
        $envelopeMaximum = ($this->maximumBytes() * 2) + 1024;
        if (! is_int($length) || $length < 1 || $length > $envelopeMaximum) {
            throw new InstructionPatchException('The instruction patch proposal has an invalid size.');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) !== $length) {
            throw new InstructionPatchException('The instruction patch proposal is incomplete.');
        }
        try {
            $safeBytes = $this->redactor->redact($bytes, $context)->text;
            $document = json_decode($safeBytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (InvalidRedactionInputException|JsonException) {
            throw new InstructionPatchException('The instruction patch proposal encoding is invalid.');
        }
        if (! is_array($document) || array_keys($document) !== ['version', 'target_path', 'content_sha256', 'content_base64'] || $document['version'] !== 1) {
            throw new InstructionPatchException('The instruction patch proposal is invalid.');
        }
        $targetPath = $document['target_path'] ?? null;
        $content = is_string($document['content_base64']) ? base64_decode($document['content_base64'], true) : false;
        if (! is_string($targetPath) || ! is_string($content) || ! is_string($document['content_sha256']) || ! hash_equals($document['content_sha256'], hash('sha256', $content))) {
            throw new InstructionPatchException('The instruction patch proposal binding is invalid.');
        }
        try {
            $content = $this->redactor->redact($content, $context)->text;
        } catch (InvalidRedactionInputException) {
            throw new InstructionPatchException('The instruction patch content is not valid UTF-8.');
        }
        if (strlen($content) > $this->maximumBytes()) {
            throw new InstructionPatchException('The instruction patch exceeds its server limit.');
        }
        $this->assertTarget($snapshot, $initialScope, $targetPath);

        return new InstructionPatchProposal($targetPath, $content, $document['content_sha256']);
    }

    private function maximumBytes(): int
    {
        $maximum = config('ai6.instruction_patch_max_bytes');
        if ((! is_int($maximum) && (! is_string($maximum) || preg_match('/\A[1-9][0-9]*\z/D', $maximum) !== 1)) || (int) $maximum > 10_000_000) {
            throw new InstructionPatchException('The instruction patch server limit is invalid.');
        }

        return (int) $maximum;
    }

    /** @param list<string> $initialScope */
    private function assertTarget(InstructionSnapshot $snapshot, array $initialScope, string $targetPath): void
    {
        if (count(array_filter($initialScope, static fn (string $path): bool => $path === $targetPath)) !== 1) {
            throw new InstructionPatchException('The instruction patch target was not approved in the initial scope.');
        }
        $snapshotPaths = array_map(static fn (InstructionSnapshotEntry $entry): string => $entry->repositoryPath, $snapshot->entries);
        if (! in_array($targetPath, $snapshotPaths, true) || str_contains('/'.str_replace('\\', '/', $targetPath).'/', '/../')) {
            throw new InstructionPatchException('The instruction patch target is not a bound discovery path.');
        }
    }
}
