<?php

namespace Tests\Unit\Runs;

use App\AI6\Projects\ProjectConfiguration;
use App\AI6\Runs\InstructionPathPolicy;
use App\AI6\Runs\ScopeCategorizer;
use App\AI6\Runs\ScopeCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** TC-01 */
final class ScopeCategorizerTest extends TestCase
{
    private function configuration(): ProjectConfiguration
    {
        return new ProjectConfiguration([
            'tickets_path' => 'tickets',
            'scope' => [
                'auto_allow' => ['app/**', 'resources/**', 'tests/**'],
                'require_approval' => ['AGENTS.md', 'CLAUDE.md', '.ai6/**', 'tickets/**'],
            ],
        ]);
    }

    /** @return array<string, array{string, bool, ScopeCategory}> */
    public static function pathMatrix(): array
    {
        return [
            'auto-allow app path' => ['app/AI6/Runs/ScopeCategorizer.php', false, ScopeCategory::AUTO_ALLOW],
            'auto-allow test path' => ['tests/Unit/Runs/Foo.php', false, ScopeCategory::AUTO_ALLOW],
            'instruction file' => ['AGENTS.md', false, ScopeCategory::SENSITIVE],
            'claude instruction file' => ['CLAUDE.md', false, ScopeCategory::SENSITIVE],
            // The provider profiles declare agents_md_nested, so a nested
            // instruction file is sensible even under the app/** auto-allow
            // glob (AC-03, AGT-009).
            'nested instruction file' => ['app/sub/AGENTS.md', false, ScopeCategory::SENSITIVE],
            'nested claude instruction file' => ['app/AI6/Runs/CLAUDE.md', false, ScopeCategory::SENSITIVE],
            'ticket file' => ['tickets/AI6-020.md', false, ScopeCategory::SENSITIVE],
            'migration' => ['database/migrations/2026_08_18_020000_add_scope_decision_contract.php', false, ScopeCategory::SENSITIVE],
            'dependency file' => ['composer.json', false, ScopeCategory::SENSITIVE],
            'dependency lock file' => ['composer.lock', false, ScopeCategory::SENSITIVE],
            'auth path' => ['app/AI6/Auth/Policies/UserPolicy.php', false, ScopeCategory::SENSITIVE],
            'ci path' => ['.github/workflows/ci.yml', false, ScopeCategory::SENSITIVE],
            'deploy path' => ['deploy/Caddyfile', false, ScopeCategory::SENSITIVE],
            'require_approval config path' => ['.ai6/config.yaml', false, ScopeCategory::SENSITIVE],
            'undetermined path' => ['docs/notes.md', false, ScopeCategory::UNDETERMINED],
            'deletion of an otherwise auto-allow path' => ['app/AI6/Runs/Old.php', true, ScopeCategory::SENSITIVE],
        ];
    }

    #[DataProvider('pathMatrix')]
    public function test_categorization_is_deterministic_from_path_and_configuration_alone(
        string $path,
        bool $isDeletion,
        ScopeCategory $expected,
    ): void {
        $categorizer = new ScopeCategorizer(new InstructionPathPolicy);
        self::assertSame($expected, $categorizer->categorize($path, $isDeletion, $this->configuration()));
        // Same input, same output — no hidden state.
        self::assertSame($expected, $categorizer->categorize($path, $isDeletion, $this->configuration()));
    }

    /** TC-01: the third track of plan §8.2 at one and the same unlisted path. */
    public function test_an_unlisted_path_stays_undetermined_for_the_project_default_to_resolve(): void
    {
        $categorizer = new ScopeCategorizer(new InstructionPathPolicy);

        // The categorizer never reads the project default itself: it names the
        // path unlisted, and ScopeApprovalService applies
        // `scope.unlisted_paths` to it. Both values therefore see the same
        // category for the same path.
        self::assertSame(
            ScopeCategory::UNDETERMINED,
            $categorizer->categorize('docs/notes.md', false, $this->configuration()),
        );
    }

    public function test_a_non_canonical_path_never_auto_allows_even_under_an_auto_allow_prefix(): void
    {
        $categorizer = new ScopeCategorizer(new InstructionPathPolicy);
        $traversalPath = 'app/AI6/Runs/../../../AGENTS.md';

        // A pattern match on the literal segments would otherwise let a traversal
        // path ride the "app/**" auto-allow glob; canonical-path rejection keeps
        // this a human decision (fail-safe default) instead of a silent bypass.
        self::assertSame(
            ScopeCategory::SENSITIVE,
            $categorizer->categorize($traversalPath, false, $this->configuration()),
        );
    }
}
