<?php

namespace Tests\Unit\Runs;

use App\AI6\Runs\InstructionPathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AC-12, TC-11: the one server-side decision what an instruction path is.
 *
 * The provider profiles in config/ai6.php declare the discovery form
 * `agents_md_nested`, so an AGENTS.md is an instruction file in every
 * directory, not only at the repository root.
 */
final class InstructionPathPolicyTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function paths(): array
    {
        return [
            'repository AGENTS.md' => ['AGENTS.md', true],
            'repository CLAUDE.md' => ['CLAUDE.md', true],
            'nested AGENTS.md' => ['app/sub/AGENTS.md', true],
            'nested CLAUDE.md' => ['app/AI6/Runs/CLAUDE.md', true],
            'deeply nested AGENTS.md' => ['a/b/c/d/AGENTS.md', true],
            'instruction directory' => ['instructions/base.md', true],
            'ai6 directory' => ['.ai6/config.yaml', true],
            'ordinary source file' => ['app/AI6/Runs/RunOrchestrator.php', false],
            'similar name is not an instruction file' => ['app/sub/AGENTS.md.bak', false],
            'agents directory is not an instruction file' => ['app/AGENTS.md/inner.php', false],
            'ticket file' => ['tickets/AI6-020.md', false],
        ];
    }

    #[DataProvider('paths')]
    public function test_instruction_paths_are_recognized_at_every_directory_depth(string $path, bool $expected): void
    {
        self::assertSame($expected, (new InstructionPathPolicy)->isInstructionPath($path));
    }
}
