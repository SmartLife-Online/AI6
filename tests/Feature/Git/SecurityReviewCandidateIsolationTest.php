<?php

namespace Tests\Feature\Git;

use App\AI6\Agents\AgentScenario;
use App\AI6\Agents\FakeAgentAdapter;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\WorktreeGitMetadataPaths;
use Tests\Feature\Reviews\BuildsSecurityReviewFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

final class SecurityReviewCandidateIsolationTest extends TicketUiTestCase
{
    use BuildsSecurityReviewFixture;

    public function test_candidate_export_has_no_git_metadata_hooks_or_writable_ref_and_index_paths(): void
    {
        $prepared = $this->preparedSecurityReview('AI6-028-TC03');
        $run = $prepared['run']->fresh();
        $identifier = (string) $run->project()->value('project_identifier');
        $repository = $this->app->make(ManagedProjectPath::class)->repositoryDirectory($identifier);
        $hookMarker = dirname($repository).'/security-hook-ran';
        $hook = $repository.'/.git/hooks/post-checkout';
        if (! is_dir(dirname($hook))) {
            self::assertTrue(mkdir(dirname($hook), 0700, true));
        }
        self::assertNotFalse(file_put_contents($hook, "#!/bin/sh\nprintf hook > \"{$hookMarker}\"\n"));
        self::assertTrue(chmod($hook, 0700));
        foreach (['.codex/plugins', '.codex/skills', '.codex/commands', '.claude', 'nested'] as $directory) {
            self::assertTrue(is_dir($run->worktree_path.'/'.$directory) || mkdir($run->worktree_path.'/'.$directory, 0700, true));
        }
        foreach ([
            '.codex/config.toml', '.codex/plugins/plugin.json', '.codex/skills/SKILL.md',
            '.codex/commands/run.md', '.claude/settings.json', '.mcp.json', 'mcp.json',
            '.gitconfig', '.git-credentials', 'nested/AGENTS.md',
        ] as $path) {
            self::assertNotFalse(file_put_contents($run->worktree_path.'/'.$path, 'malicious provider configuration'));
        }
        $refsBefore = $this->gitOutput(['show-ref'], $repository);
        $metadata = $this->app->make(WorktreeGitMetadataPaths::class)->resolve((string) $run->worktree_path);
        self::assertNotEmpty($metadata);
        $adapter = new FakeAgentAdapter(AgentScenario::SUCCESS, additionalPathProbes: $metadata);
        $this->app->instance(FakeAgentAdapter::class, $adapter);

        $job = $this->executeSecurityReview($run);

        self::assertSame('succeeded', $job->state->value, (string) $job->failure_code);
        self::assertFileDoesNotExist($hookMarker);
        self::assertSame($refsBefore, $this->gitOutput(['show-ref'], $repository));
        foreach (['.git', '.git/refs', '.git/hooks', '.git/commondir', '../.git'] as $path) {
            self::assertSame('missing', $adapter->lastAccessProbes['workspace:'.$path] ?? null, $path);
        }
        foreach ([
            '.codex', '.codex/config.toml', '.codex/plugins/plugin.json', '.codex/skills/SKILL.md',
            '.codex/commands/run.md', '.claude/settings.json', '.mcp.json', 'mcp.json', '.gitconfig',
            '.git-credentials', 'nested/AGENTS.md',
        ] as $path) {
            self::assertSame('missing', $adapter->lastAccessProbes['workspace:'.$path] ?? null, $path);
        }
        self::assertSame('denied', $adapter->lastAccessProbes['write:existing'] ?? null);
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('denied', $adapter->lastAccessProbes['write:new'] ?? null);
        }
        foreach ($metadata as $path) {
            self::assertSame('denied', $adapter->lastAccessProbes['path:'.$path] ?? null, $path);
            self::assertSame('denied', $adapter->lastAccessProbes['path-write:'.$path] ?? null, $path);
        }
    }
}
