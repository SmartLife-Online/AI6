<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\CredentialProjection as AuthProjection;
use App\AI6\Agents\CredentialProjectionException as AuthProjectionException;
use App\AI6\Agents\CredentialRevisionRegistry as AuthRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeException;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\InstructionDiscovery;
use App\AI6\Agents\InstructionPatchChannel;
use App\AI6\Agents\InstructionPatchException;
use App\AI6\Agents\InstructionResolutionProfile;
use App\AI6\Agents\InstructionSnapshot;
use App\AI6\Agents\InstructionSnapshotEntry;
use App\AI6\Agents\ProviderRuntimeProfile;
use App\AI6\Git\CanonicalJson;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionPolicy;
use App\AI6\Shared\Redaction\RedactionRuleSet;
use App\AI6\Shared\Redaction\Redactor;
use Tests\TestCase;

final class ExecutionHomeManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ai6-execution-home-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/export', 0700, true);
        mkdir($this->root.'/inputs', 0700, true);
        mkdir($this->root.'/outputs', 0700, true);
        file_put_contents($this->root.'/export/source.php', '<?php');
        file_put_contents($this->root.'/auth.json', '{"profile":"a"}');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function test_it_materializes_only_bound_snapshot_runtime_and_profile_auth_then_destroys_home(): void
    {
        $manager = $this->manager();
        [$profile, $snapshot, $runtime] = $this->bindings();
        $projection = new AuthProjection('codex_cli', 'revision-1', ['auth.json' => $this->root.'/auth.json']);
        $home = $manager->create($this->root.'/inputs', $this->root.'/outputs', 'slot-1', 'session-1', $this->root.'/export', $profile, $snapshot, $runtime, $projection);

        self::assertFileExists($home->workspace.'/source.php');
        self::assertSame("bound instructions\n", file_get_contents($home->workspace.'/AGENTS.md'));
        self::assertSame("bound instructions\n", file_get_contents($home->instructionOverlay.'/AGENTS.md'));
        self::assertSame('{"profile":"a"}', file_get_contents($home->authDirectory.'/auth.json'));
        self::assertFileDoesNotExist($home->home.'/.gitconfig');
        self::assertFileDoesNotExist($home->home.'/.codex/config.toml');
        self::assertStringContainsString($runtime->hash, (string) file_get_contents($home->runtimeConfiguration));
        if (DIRECTORY_SEPARATOR === '/') {
            self::assertSame('550', substr(sprintf('%o', fileperms($home->root)), -3));
            self::assertSame('440', substr(sprintf('%o', fileperms($home->workspace.'/AGENTS.md')), -3));
        }

        $manager->destroy($home);
        self::assertDirectoryDoesNotExist($home->root);
    }

    public function test_auth_revision_and_instruction_patch_scope_fail_closed(): void
    {
        [$profile, $snapshot, $runtime] = $this->bindings();
        $this->expectException(AuthProjectionException::class);
        $this->manager('revision-2')->create(
            $this->root.'/inputs',
            $this->root.'/outputs',
            'slot-1',
            null,
            $this->root.'/export',
            $profile,
            $snapshot,
            $runtime,
            new AuthProjection('codex_cli', 'revision-1', []),
        );
    }

    public function test_writable_modes_ignore_a_restrictive_umask_and_sealed_home_is_destroyed(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('POSIX mode and sealed-directory cleanup evidence requires POSIX.');
        }

        $previous = umask(0077);
        try {
            [$profile, $snapshot, $runtime] = $this->bindings();
            $manager = $this->manager();
            $home = $manager->create($this->root.'/inputs', $this->root.'/outputs', 'slot-modes', null, $this->root.'/export', $profile, $snapshot, $runtime, new AuthProjection('codex_cli', 'revision-1', []));

            foreach ([$home->outputRoot, $home->resultDirectory, $home->artifactDirectory, $home->patchDirectory] as $directory) {
                self::assertSame('1730', substr(sprintf('%o', fileperms($directory)), -4));
            }
            self::assertSame('550', substr(sprintf('%o', fileperms($home->root)), -3));

            $manager->destroy($home);
            self::assertDirectoryDoesNotExist($home->root);
            self::assertDirectoryDoesNotExist($home->outputRoot);
        } finally {
            umask($previous);
        }
    }

    public function test_destroy_reports_an_incomplete_teardown(): void
    {
        $invalidRoot = $this->root.'/not-a-directory';
        file_put_contents($invalidRoot, 'occupied');
        $home = new ExecutionHome($invalidRoot, $this->root.'/missing-output', '', '', '', '', '', '', '', '');

        $this->expectException(ExecutionHomeException::class);
        $this->expectExceptionMessage('The isolated execution home could not be destroyed completely');
        $this->manager()->destroy($home);
    }

    public function test_failed_creation_preserves_its_original_cause_when_cleanup_also_fails(): void
    {
        $invalidRoot = $this->root.'/failed-home';
        file_put_contents($invalidRoot, 'occupied');
        $home = new ExecutionHome($invalidRoot, $this->root.'/missing-output', '', '', '', '', '', '', '', '');
        $original = new ExecutionHomeException('The original creation failure.');
        $cleanup = new \ReflectionMethod(ExecutionHomeManager::class, 'cleanupFailedCreation');

        try {
            $cleanup->invoke($this->manager(), $home, $original);
            self::fail('A failed cleanup must not replace the creation failure.');
        } catch (ExecutionHomeException $exception) {
            self::assertSame('The isolated execution home creation failed and cleanup was incomplete.', $exception->getMessage());
            self::assertSame($original, $exception->getPrevious());
            self::assertSame('The original creation failure.', $exception->getPrevious()->getMessage());
        }
    }

    public function test_instruction_patch_is_single_target_and_worker_only(): void
    {
        $manager = $this->manager();
        [$profile, $snapshot, $runtime] = $this->bindings();
        $home = $manager->create($this->root.'/inputs', $this->root.'/outputs', 'slot-1', null, $this->root.'/export', $profile, $snapshot, $runtime, new AuthProjection('codex_cli', 'revision-1', []));
        $channel = new InstructionPatchChannel($this->redactor());
        $context = new RedactionContext('project-1', 'run-1', 'instruction-patch');
        $proposal = $channel->propose($home, $snapshot, ['AGENTS.md'], 'AGENTS.md', "new instructions\n", $context);
        self::assertSame("bound instructions\n", file_get_contents($home->workspace.'/AGENTS.md'));
        config(['ai6.runtime_role' => 'worker']);
        self::assertSame($proposal->hash, $channel->readForWorker($home, $snapshot, ['AGENTS.md'], $context)->hash);

        try {
            $channel->propose($home, $snapshot, ['AGENTS.md'], 'AGENTS.md', 'second', $context);
            self::fail('A second patch must fail.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('Exactly one instruction patch may be proposed.', $exception->getMessage());
        }
        try {
            config(['ai6.runtime_role' => 'agent']);
            $channel->readForWorker($home, $snapshot, ['AGENTS.md'], $context);
            self::fail('An executor must not consume the patch.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('Only the worker may consume an instruction patch proposal.', $exception->getMessage());
        }

        $document = json_decode((string) file_get_contents($home->patchDirectory.'/proposal.json'), true, 8, JSON_THROW_ON_ERROR);
        $document['target_path'] = 'OTHER.md';
        file_put_contents($home->patchDirectory.'/proposal.json', json_encode($document, JSON_THROW_ON_ERROR)."\n");
        try {
            config(['ai6.runtime_role' => 'worker']);
            $channel->readForWorker($home, $snapshot, ['AGENTS.md'], $context);
            self::fail('The worker must recheck the target binding after execution.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('The instruction patch target was not approved in the initial scope.', $exception->getMessage());
        }

        $manager->destroy($home);
    }

    public function test_patch_target_must_be_a_snapshot_path_or_explicitly_approved_new_path(): void
    {
        $patchDirectory = $this->root.'/unbound-patch';
        mkdir($patchDirectory, 0700, true);
        $home = new ExecutionHome('', '', '', '', '', '', '', '', '', $patchDirectory);
        $snapshot = new InstructionSnapshot('codex_cli', [new InstructionSnapshotEntry('agent', 'repository', 1, 'AGENTS.md', str_repeat('a', 40), 'bound', [])], str_repeat('b', 64));
        $channel = new InstructionPatchChannel($this->redactor());
        $context = new RedactionContext('project-1', 'run-1', 'unbound-patch');

        try {
            $channel->propose($home, $snapshot, ['README.md'], 'README.md', 'unsafe', $context);
            self::fail('An unbound scope path was accepted.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('The instruction patch target is not a bound discovery path.', $exception->getMessage());
        }
        self::assertSame([], array_values(array_diff(scandir($patchDirectory) ?: [], ['.', '..'])));

        file_put_contents($patchDirectory.'/proposal.json', json_encode([
            'version' => 1,
            'target_path' => 'README.md',
            'content_sha256' => hash('sha256', 'unsafe'),
            'content_base64' => base64_encode('unsafe'),
        ], JSON_THROW_ON_ERROR)."\n");
        config(['ai6.runtime_role' => 'worker']);
        try {
            $channel->readForWorker($home, $snapshot, ['README.md'], $context);
            self::fail('The worker accepted an unbound scope path.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('The instruction patch target is not a bound discovery path.', $exception->getMessage());
        }
    }

    public function test_isolation_negative_matrix_exposes_only_the_bound_snapshot_and_exported_source(): void
    {
        foreach (['.git/hooks', '.codex/plugins', '.codex/skills', '.codex/commands', '.claude', 'nested'] as $directory) {
            mkdir($this->root.'/export/'.$directory, 0700, true);
        }
        foreach ([
            '.git/hooks/post-checkout', '.codex/config.toml', '.codex/plugins/plugin.json', '.codex/skills/SKILL.md',
            '.codex/commands/run.md', '.claude/settings.json', '.mcp.json', 'mcp.json', '.gitconfig', '.git-credentials',
            'nested/AGENTS.md',
        ] as $path) {
            file_put_contents($this->root.'/export/'.$path, 'host-controlled');
        }
        file_put_contents($this->root.'/AGENTS.md', 'parent instructions');

        [$profile, $snapshot, $runtime] = $this->bindings();
        $manager = $this->manager();
        $home = $manager->create($this->root.'/inputs', $this->root.'/outputs', 'slot-matrix', null, $this->root.'/export', $profile, $snapshot, $runtime, new AuthProjection('codex_cli', 'revision-1', []));

        self::assertSame("bound instructions\n", file_get_contents($home->workspace.'/AGENTS.md'));
        foreach (['.git', '.codex', '.claude', '.mcp.json', 'mcp.json', '.gitconfig', '.git-credentials', 'nested/AGENTS.md'] as $path) {
            self::assertFileDoesNotExist($home->workspace.'/'.$path);
        }
        self::assertFileDoesNotExist($home->home.'/.config');
        self::assertFileDoesNotExist($home->home.'/.cache');
        self::assertFileDoesNotExist($home->home.'/.history');
        self::assertSame("bound instructions\n", file_get_contents($home->instructionOverlay.'/AGENTS.md'));
        $manager->destroy($home);

        $changed = new InstructionSnapshot('codex_cli', [new InstructionSnapshotEntry('agents_md', 'repository', 10, 'AGENTS.md', str_repeat('a', 40), "changed\n", [])], str_repeat('b', 64));
        $changedHome = $manager->create($this->root.'/inputs', $this->root.'/outputs', 'slot-changed', null, $this->root.'/export', $profile, $changed, $runtime, new AuthProjection('codex_cli', 'revision-1', []));
        self::assertSame("changed\n", file_get_contents($changedHome->instructionOverlay.'/AGENTS.md'));
        self::assertSame($changed->entries[0]->contentSha256, hash_file('sha256', $changedHome->instructionOverlay.'/AGENTS.md'));
        $manager->destroy($changedHome);
    }

    public function test_instruction_patch_rejects_invalid_utf8_oversize_and_malformed_worker_input(): void
    {
        [$profile, $snapshot, $runtime] = $this->bindings();
        $manager = $this->manager();
        $home = $manager->create($this->root.'/inputs', $this->root.'/outputs', 'slot-invalid', null, $this->root.'/export', $profile, $snapshot, $runtime, new AuthProjection('codex_cli', 'revision-1', []));
        $channel = new InstructionPatchChannel($this->redactor());
        $context = new RedactionContext('project-1', 'run-1', 'instruction-patch-invalid');

        try {
            $channel->propose($home, $snapshot, ['AGENTS.md'], 'AGENTS.md', "\xFF", $context);
            self::fail('Invalid UTF-8 must be rejected before serialization.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('The instruction patch content is not valid UTF-8.', $exception->getMessage());
        }

        config(['ai6.instruction_patch_max_bytes' => 4]);
        try {
            $channel->propose($home, $snapshot, ['AGENTS.md'], 'AGENTS.md', '12345', $context);
            self::fail('Oversized patch content must be rejected.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('The instruction patch exceeds its server limit.', $exception->getMessage());
        }

        file_put_contents($home->patchDirectory.'/proposal.json', '{broken');
        config(['ai6.runtime_role' => 'worker', 'ai6.instruction_patch_max_bytes' => 100]);
        try {
            $channel->readForWorker($home, $snapshot, ['AGENTS.md'], $context);
            self::fail('Malformed patch input must be terminalized.');
        } catch (InstructionPatchException $exception) {
            self::assertSame('The instruction patch proposal encoding is invalid.', $exception->getMessage());
        }

        $manager->destroy($home);
    }

    private function manager(string $revision = 'revision-1'): ExecutionHomeManager
    {
        return new ExecutionHomeManager(new CanonicalJson, new AuthRevisionRegistry(['codex_cli' => $revision]));
    }

    private function redactor(): Redactor
    {
        return new Redactor(
            new RedactionPolicy(RedactionRuleSet::defaults()),
            new RedactionFingerprintGenerator(new RedactionKeyring('test-v1', ['test-v1' => ['version' => 1, 'key' => str_repeat('k', 32)]])),
        );
    }

    /** @return array{InstructionResolutionProfile, InstructionSnapshot, ProviderRuntimeProfile} */
    private function bindings(): array
    {
        $entry = new InstructionSnapshotEntry('agents_md', 'repository', 10, 'AGENTS.md', str_repeat('a', 40), "bound instructions\n", []);

        return [
            new InstructionResolutionProfile('codex_cli', ['agents_md' => new InstructionDiscovery('agents_md', 10, 'repository')]),
            new InstructionSnapshot('codex_cli', [$entry], str_repeat('b', 64)),
            new ProviderRuntimeProfile('codex-cli-v1', 1, [], ['network' => false, 'workspace' => 'read_only'], ['mcp_servers' => [], 'plugins' => [], 'skills' => [], 'hooks' => [], 'commands' => [], 'agent_definitions' => [], 'external_helpers' => []], str_repeat('c', 64)),
        ];
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            @chmod($entry->getPathname(), 0700);
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
