<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Agents\InstructionCandidate;
use App\AI6\Agents\InstructionCandidateOrigin;
use App\AI6\Agents\InstructionFileType;
use App\AI6\Agents\InstructionProfileRegistry;
use App\AI6\Agents\InstructionResolutionError;
use App\AI6\Agents\InstructionResolutionException;
use App\AI6\Agents\InstructionSnapshotResolver;
use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Tests\TestCase;

final class InstructionSnapshotResolverTest extends TestCase
{
    public function test_order_and_hash_are_stable_and_bind_path_blob_and_effective_content(): void
    {
        $resolver = $this->resolver(new AgentInputLimits(4, 100, 200, 4, 100));
        $entries = [
            $this->candidate('docs/nested.md', 'b', imports: ['AGENTS.md'], discovery: 'agents_md_nested'),
            $this->candidate('AGENTS.md', 'a'),
        ];
        $first = $resolver->resolve('fake', $entries, $this->context());
        $second = $resolver->resolve('fake', array_reverse($entries), $this->context());

        self::assertSame(['AGENTS.md', 'docs/nested.md'], array_column($first->entries, 'repositoryPath'));
        self::assertSame(['repository', 'nested'], array_column($first->entries, 'scope'));
        self::assertSame(hash('sha256', 'a'), $first->entries[0]->contentSha256);
        self::assertSame($first->entries[0]->contentSha256, $first->jsonSerialize()['entries'][0]['content_sha256']);
        self::assertSame($first->hash, $second->hash);
        self::assertSame($first->hash, $resolver->resolve('fake', $entries, $this->context())->hash);

        $changes = [
            [$this->candidate('docs/changed.md', 'b', imports: ['AGENTS.md'], discovery: 'agents_md_nested'), $entries[1]],
            [$this->candidate('docs/nested.md', 'b', blob: str_repeat('b', 40), imports: ['AGENTS.md'], discovery: 'agents_md_nested'), $entries[1]],
            [$this->candidate('docs/nested.md', 'changed', imports: ['AGENTS.md'], discovery: 'agents_md_nested'), $entries[1]],
        ];
        foreach ($changes as $changed) {
            self::assertNotSame($first->hash, $resolver->resolve('fake', $changed, $this->context())->hash);
        }
    }

    public function test_negative_boundary_matrix_is_named_value_free_and_returns_no_snapshot(): void
    {
        $cases = [
            [$this->candidate('AGENTS.md', 'x', origin: InstructionCandidateOrigin::HOST), InstructionResolutionError::HOST_SOURCE_FORBIDDEN],
            [$this->candidate('AGENTS.md', 'x', origin: InstructionCandidateOrigin::PARENT), InstructionResolutionError::PARENT_SOURCE_FORBIDDEN],
            [$this->candidate('AGENTS.md', 'x', exists: false), InstructionResolutionError::FILE_MISSING],
            [$this->candidate('AGENTS.md', 'x', type: InstructionFileType::SYMLINK), InstructionResolutionError::SYMLINK_FORBIDDEN],
            [$this->candidate('/host/outside', 'x'), InstructionResolutionError::PATH_INVALID],
            [$this->candidate('../parent', 'x'), InstructionResolutionError::PATH_INVALID],
            [$this->candidate('AGENTS.md', 'x', discovery: 'unknown'), InstructionResolutionError::DISCOVERY_UNKNOWN],
            [$this->candidate('AGENTS.md', "\xC3\x28"), InstructionResolutionError::UTF8_INVALID],
        ];

        foreach ($cases as [$candidate, $reason]) {
            try {
                $this->resolver(new AgentInputLimits(4, 100, 200, 4, 100))->resolve('fake', [$candidate], $this->context());
                self::fail('The invalid instruction candidate unexpectedly resolved.');
            } catch (InstructionResolutionException $exception) {
                self::assertSame($reason, $exception->reason);
                self::assertSame('Instruction resolution failed: '.$reason->value.'.', $exception->getMessage());
                self::assertStringNotContainsString($candidate->repositoryPath, $exception->getMessage());
                self::assertStringNotContainsString($candidate->content, $exception->getMessage());
            }
        }
    }

    public function test_every_instruction_limit_accepts_the_maximum_and_rejects_one_over(): void
    {
        $context = $this->context();
        self::assertCount(1, $this->resolver(new AgentInputLimits(1, 1, 1, 1, 1))
            ->resolve('fake', [$this->candidate('a', 'x')], $context)->entries);

        $cases = [
            [new AgentInputLimits(1, 10, 20, 4, 10), [$this->candidate('a', 'x'), $this->candidate('b', 'y')], InstructionResolutionError::FILE_COUNT_EXCEEDED],
            [new AgentInputLimits(2, 1, 20, 4, 10), [$this->candidate('a', 'xx')], InstructionResolutionError::FILE_BYTES_EXCEEDED],
            [new AgentInputLimits(2, 2, 1, 4, 10), [$this->candidate('a', 'x'), $this->candidate('b', 'y')], InstructionResolutionError::TOTAL_BYTES_EXCEEDED],
            [new AgentInputLimits(2, 2, 4, 1, 10), [$this->candidate('a', 'x', imports: ['b']), $this->candidate('b', 'y')], InstructionResolutionError::IMPORT_DEPTH_EXCEEDED],
        ];
        foreach ($cases as [$limits, $entries, $reason]) {
            try {
                $this->resolver($limits)->resolve('fake', $entries, $context);
                self::fail('The over-limit input unexpectedly resolved.');
            } catch (InstructionResolutionException $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }

        $invalidUtf8 = "\xC3\x28";
        $preRedactionCases = [
            [new AgentInputLimits(1, 1, 10, 1, 1), InstructionResolutionError::FILE_BYTES_EXCEEDED],
            [new AgentInputLimits(1, 10, 1, 1, 1), InstructionResolutionError::TOTAL_BYTES_EXCEEDED],
        ];
        foreach ($preRedactionCases as [$limits, $reason]) {
            try {
                $this->resolver($limits)->resolve('fake', [$this->candidate('a', $invalidUtf8)], $context);
                self::fail('The oversized raw instruction unexpectedly reached redaction.');
            } catch (InstructionResolutionException $exception) {
                self::assertSame($reason, $exception->reason);
                self::assertStringNotContainsString($invalidUtf8, $exception->getMessage());
            }
        }
    }

    public function test_import_cycles_and_nfc_path_duplicates_block_before_release(): void
    {
        $resolver = $this->resolver(new AgentInputLimits(4, 100, 200, 4, 100));
        $cases = [
            [
                [$this->candidate('a.md', 'a', imports: ['b.md']), $this->candidate('b.md', 'b', imports: ['a.md'])],
                InstructionResolutionError::IMPORT_CYCLE,
            ],
            [
                [$this->candidate("docs/e\u{301}.md", 'a'), $this->candidate('docs/é.md', 'b')],
                InstructionResolutionError::PATH_DUPLICATE,
            ],
        ];
        foreach ($cases as [$entries, $reason]) {
            try {
                $resolver->resolve('fake', $entries, $this->context());
                self::fail('The invalid import graph unexpectedly resolved.');
            } catch (InstructionResolutionException $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }
    }

    private function resolver(AgentInputLimits $limits): InstructionSnapshotResolver
    {
        return new InstructionSnapshotResolver(
            new InstructionProfileRegistry(new StrictPositiveIntegerParser),
            $limits,
            $this->app->make(Redactor::class),
            $this->app->make(CanonicalJson::class),
        );
    }

    /** @param list<string> $imports */
    private function candidate(
        string $path,
        string $content,
        string $blob = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        array $imports = [],
        InstructionCandidateOrigin $origin = InstructionCandidateOrigin::REPOSITORY,
        bool $exists = true,
        InstructionFileType $type = InstructionFileType::REGULAR,
        string $discovery = 'agents_md',
    ): InstructionCandidate {
        return new InstructionCandidate($discovery, $origin, $exists, $type, $path, $blob, $content, $imports);
    }

    private function context(): RedactionContext
    {
        return new RedactionContext('project-test', null, 'instruction-snapshot');
    }
}
