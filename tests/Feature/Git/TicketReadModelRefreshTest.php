<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueControlBranchChange;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlBranchChanger;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationOutcome;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationRetryableConflict;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationTerminalConflict;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitRemotePolicy;
use App\AI6\Git\HardenedGitEnvironment;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\KnownHostsVerifier;
use App\AI6\Git\ManagedCloneSynchronizer;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Git\TicketReadModelRefresher;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectReadModelStatus;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelFreshness;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class TicketReadModelRefreshTest extends ControlOperationTestCase
{
    use BuildsManagedControlRuntimeFixture;

    private ?string $fixtureRoot = null;

    public function test_worker_projects_redacted_blob_once_and_staleness_stays_a_read_time_comparison(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $fixture = $this->configureRepository($project);
        $operation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            'tickets/AI6-006F.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $lease = $this->app->make(ProjectOperationLease::class);
        self::assertSame(1, $lease->claim($operation, str_repeat('b', 32)));

        $refresher = $this->app->make(TicketReadModelRefresher::class);
        self::assertTrue($refresher->advance($operation->refresh(), 1));
        self::assertTrue($refresher->advance($operation->refresh(), 1));

        $readModel = TicketReadModel::query()->sole();
        self::assertSame($project->getKey(), $readModel->project_id);
        self::assertSame($operation->id, $readModel->control_operation_id);
        self::assertSame('tickets/AI6-006F.md', $readModel->relative_path);
        self::assertSame($fixture['control_oid'], $readModel->control_commit);
        self::assertSame($fixture['blob_sha'], $readModel->blob_sha);
        self::assertSame(0, $readModel->control_generation);
        self::assertNull($readModel->validation_profile);
        self::assertSame(TicketDocumentState::UNPARSED, $readModel->document_state);
        self::assertNull($readModel->ticket_contract_sha256);
        self::assertSame(TicketReadModelRedactionState::CONTENT_REDACTED, $readModel->redaction_state);
        self::assertFalse($readModel->approval_editor_eligible);
        self::assertSame(['unparsed', 'content_redacted'], $readModel->source_blockers);
        self::assertStringContainsString('[REDACTED:SECRET]', $readModel->redacted_content);
        self::assertStringNotContainsString('hunter2', $readModel->redacted_content);
        self::assertCount(1, $readModel->redaction_matches);
        $match = $readModel->redaction_matches[0];
        self::assertSame('secret', $match['type']);
        self::assertSame('ticket-read-model:tickets/AI6-006F.md', $match['field']);
        self::assertSame('[REDACTED:SECRET]', $match['marker']);
        self::assertIsInt($match['start']);
        self::assertIsInt($match['length']);
        self::assertIsInt($match['fingerprint_version']);
        self::assertNotSame('', $match['key_id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $match['fingerprint']);
        $otherProjectRedaction = $this->app->make(Redactor::class)->redact(
            "password=hunter2\n",
            new RedactionContext('different-project', null, 'ticket-read-model:tickets/AI6-006F.md'),
        );
        self::assertNotSame($match['fingerprint'], $otherProjectRedaction->matches[0]->fingerprint);
        self::assertSame(1, $operation->result()->count());
        self::assertSame(ControlOperationOutcome::SUCCEEDED, $operation->result()->sole()->outcome);
        self::assertSame(1, TicketReadModel::query()->count());

        $secondOperation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            'tickets/subdir/nested.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $secondToken = $lease->claim($secondOperation, str_repeat('f', 32));
        self::assertIsInt($secondToken);
        self::assertTrue($refresher->advance($secondOperation->refresh(), $secondToken));
        self::assertTrue($refresher->advance($secondOperation->refresh(), $secondToken));
        self::assertSame(2, TicketReadModel::query()->count());

        $this->actingAs($administrator)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('tickets/AI6-006F.md')
            ->assertSee('tickets/subdir/nested.md')
            ->assertSee($fixture['control_oid'])
            ->assertSee($fixture['blob_sha'])
            ->assertSee('Aktuell');
        $statusReadModels = $this->app->make(ProjectReadModelStatus::class)->for($project->refresh())['readModels'];
        self::assertCount(2, $statusReadModels);
        self::assertSame([
            'id',
            'project_id',
            'relative_path',
            'control_commit',
            'blob_sha',
            'control_generation',
            'document_state',
            'redaction_state',
            'source_blockers',
            'approval_editor_eligible',
            'generated_at',
        ], array_keys($statusReadModels[0]['readModel']->getAttributes()));

        $generatedAt = $readModel->generated_at->toJSON();
        $project->forceFill(['control_generation' => 1])->save();
        $this->actingAs($administrator)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('control_generation_mismatch');
        self::assertSame($generatedAt, $readModel->refresh()->generated_at->toJSON());

        $project->forceFill(['control_oid' => str_repeat('c', 64)])->save();
        $this->actingAs($administrator)->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('control_commit_mismatch');
        self::assertSame($generatedAt, $readModel->refresh()->generated_at->toJSON());
    }

    public function test_git_tree_rejects_symlink_non_blob_and_case_mismatch_without_a_read_model(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $fixture = $this->configureRepository($project);
        $runner = $this->app->make(HardenedGitRunner::class);
        $context = new RedactionContext((string) $project->getKey(), null, 'ticket-read-model-fixture');

        foreach (['tickets/link.md', 'tickets/subdir', 'tickets/ai6-006f.md'] as $path) {
            try {
                $runner->readRegularBlob($fixture['repository'], $fixture['control_oid'], $path, $context);
                self::fail('An unsafe or case-inexact Git path was accepted.');
            } catch (ControlOperationTerminalConflict $exception) {
                self::assertContains($exception->conflict, [
                    'refresh_path_missing_or_ambiguous',
                    'refresh_path_not_regular_blob',
                ]);
            }
        }

        self::assertSame(0, TicketReadModel::query()->count());
    }

    public function test_repository_configuration_cannot_select_a_path_outside_the_server_base(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRepository($project);

        try {
            $this->app->make(QueueTicketReadModelRefresh::class)->handle(
                $administrator,
                $project->refresh(),
                '.ai6/config.yaml',
                (string) Str::uuid(),
            );
            self::fail('Repository configuration replaced the server refresh base path.');
        } catch (ControlOperationConflict) {
        }

        self::assertSame(0, ControlOperation::query()->count());
        self::assertSame(0, TicketReadModel::query()->count());
        self::assertSame(0, DB::table('jobs')->count());
    }

    public function test_non_utf8_blob_produces_a_named_terminal_conflict_without_a_read_model(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $this->configureRepository($project);
        $operation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            'tickets/invalid-utf8.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $token = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('e', 32));
        self::assertIsInt($token);

        try {
            $this->app->make(TicketReadModelRefresher::class)->advance($operation->refresh(), $token);
            self::fail('An invalid UTF-8 blob reached the read-model projection.');
        } catch (ControlOperationTerminalConflict $exception) {
            self::assertSame('refresh_blob_not_utf8', $exception->conflict);
            self::assertStringNotContainsString("\xC3\x28", $exception->getMessage());
        }

        self::assertSame(0, TicketReadModel::query()->count());
        self::assertSame(ControlOperationPhase::CLAIMED, $operation->refresh()->phase);
    }

    public function test_non_utf8_refresh_is_terminalized_by_the_real_executor(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The real ticket-refresh executor proof requires the Linux runtime.');
        }

        $fixture = $this->managedFixture();
        $project = $fixture['project'];
        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $project->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);

        $invalidRefresh = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $fixture['administrator'],
            $project->refresh(),
            'tickets/invalid-utf8.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($invalidRefresh->id);
        $invalidResult = ControlOperationResult::query()
            ->where('control_operation_id', $invalidRefresh->id)
            ->sole();
        self::assertSame(ControlOperationState::FAILED, $invalidRefresh->refresh()->state);
        self::assertSame(ControlOperationPhase::ATTEMPT_COMPLETED, $invalidRefresh->phase);
        self::assertSame(ControlOperationOutcome::FAILED, $invalidResult->outcome);
        self::assertSame('Der gebundene Git-Blob enthält kein gültiges UTF-8.', $invalidResult->safe_summary);
        self::assertNull($project->refresh()->operation_lock_operation_id);
        self::assertFalse(TicketReadModel::query()->where('relative_path', 'tickets/invalid-utf8.md')->exists());
    }

    public function test_outcome_published_binding_cas_loss_uses_only_read_time_freshness_through_recovery(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The managed-fetch outcome-published recovery proof requires the Linux runtime.');
        }

        $fixture = $this->managedFixture();
        $project = $fixture['project'];
        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $project->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);

        $refresh = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $fixture['administrator'],
            $project->refresh(),
            'tickets/read-model.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($refresh->id);
        $readModel = TicketReadModel::query()->where('relative_path', 'tickets/read-model.md')->sole();
        $generatedAt = $readModel->generated_at->toJSON();
        $freshness = $this->app->make(TicketReadModelFreshness::class);
        self::assertSame(['stale' => false, 'reasons' => []], $freshness->for($project->refresh(), $readModel));

        self::assertNotFalse(file_put_contents($fixture['source'].'/ticket.md', "recovery target\n"));
        $this->managedFixtureGit(['commit', '-am', 'recovery target'], $fixture['source']);
        $targetOid = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
        $this->managedFixtureGit(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);
        $fetch = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $project->refresh(),
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($fetch, str_repeat('2', 32));
        self::assertIsInt($attemptToken);
        $synchronizer = $this->app->make(ManagedCloneSynchronizer::class);
        self::assertFalse($synchronizer->advance($fetch, $attemptToken));
        self::assertSame(ControlOperationPhase::EFFECT_STAGED, $fetch->refresh()->phase);
        self::assertFalse($synchronizer->advance($fetch, $attemptToken));
        self::assertSame(ControlOperationPhase::OUTCOME_PUBLISHED, $fetch->refresh()->phase);

        self::assertSame(1, Project::query()
            ->whereKey($project->getKey())
            ->where('operation_lock_operation_id', $fetch->id)
            ->where('operation_lock_attempt_token', $attemptToken)
            ->update(['control_binding_version' => DB::raw('control_binding_version + 1')]));
        self::assertTrue($fixture['lease']->expire($fetch->id, $project->getKey(), $attemptToken));
        $this->app->make(ControlOperationExecutor::class)->execute($fetch->id);

        $fetch->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $fetch->state);
        self::assertSame(ControlOperationPhase::RECOVERY_REQUIRED, $fetch->phase);
        self::assertSame($fetch->id, $project->operation_lock_operation_id);
        self::assertSame($fixture['first_oid'], $project->control_oid);
        self::assertSame(2, $project->control_binding_version);
        self::assertSame(['stale' => false, 'reasons' => []], $freshness->for($project, $readModel->refresh()));
        self::assertSame($generatedAt, $readModel->generated_at->toJSON());
        try {
            $this->app->make(QueueManagedCloneOperation::class)->handle(
                $fixture['administrator'],
                $project,
                ControlOperationType::MANAGED_FETCH,
                (string) Str::uuid(),
            );
            self::fail('A second mutating operation bypassed the visible recovery lock.');
        } catch (ControlOperationConflict) {
            self::assertSame($fetch->id, $project->refresh()->operation_lock_operation_id);
        }

        DB::table('jobs')->delete();
        $this->travel(2)->seconds();
        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        self::assertSame(ControlOperationState::RECOVERY_REQUIRED, $fetch->refresh()->state);
        self::assertSame($fetch->id, $project->refresh()->operation_lock_operation_id);
        DB::table('jobs')->delete();

        ControlOperationRecoveryDecision::query()->create([
            'id' => (string) Str::uuid(),
            'control_operation_id' => $fetch->id,
            'actor_id' => $fixture['administrator']->getKey(),
            'decision' => RecoveryDecisionType::RETRY_RECONCILIATION,
            'expected_attempt_token' => $fetch->recovery_attempt_token,
            'expected_operation_version' => $fetch->recovery_version,
            'finding_hash' => $fetch->finding_hash,
            'state' => 'pending',
        ]);
        $this->app->make(ControlOperationExecutor::class)->execute($fetch->id);

        $fetch->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $fetch->state);
        self::assertSame(ControlOperationPhase::ATTEMPT_COMPLETED, $fetch->phase);
        self::assertSame($targetOid, $project->control_oid);
        self::assertSame(3, $project->control_binding_version);
        self::assertSame(0, $project->control_generation);
        self::assertSame(
            ['stale' => true, 'reasons' => ['control_commit_mismatch']],
            $freshness->for($project, $readModel->refresh()),
        );
        self::assertSame($generatedAt, $readModel->generated_at->toJSON());
        self::assertNull($project->operation_lock_operation_id);
    }

    public function test_branch_publish_fencing_rollback_and_success_use_only_read_time_freshness(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $fixture = $this->configureRepository($project);
        $readModel = $this->refreshPath($administrator, $project, 'tickets/AI6-006F.md');
        $freshness = $this->app->make(TicketReadModelFreshness::class);
        $generatedAt = $readModel->generated_at->toJSON();
        self::assertSame(['stale' => false, 'reasons' => []], $freshness->for($project->refresh(), $readModel));

        $operation = $this->app->make(QueueControlBranchChange::class)->handle(
            $this->stepUpRequest($administrator),
            $administrator,
            $project->refresh(),
            'refs/heads/next',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $lease = $this->app->make(ProjectOperationLease::class);
        $token = $lease->claim($operation, str_repeat('9', 32));
        self::assertIsInt($token);
        DB::table('control_operations')->where('id', $operation->id)->update([
            'phase' => ControlOperationPhase::REMOTE_PROBED->value,
            'target_control_oid' => str_repeat('b', 64),
            'version' => DB::raw('version + 1'),
        ]);
        $operation->refresh();

        self::assertTrue($lease->expire($operation->id, $project->getKey(), $token));
        $this->travel(2)->seconds();
        $winningToken = $lease->claim($operation->refresh(), str_repeat('a', 32));
        self::assertIsInt($winningToken);
        self::assertSame($token + 1, $winningToken);
        try {
            $this->app->make(ControlBranchChanger::class)->advance($operation, $token);
            self::fail('A fenced publish changed the project control state.');
        } catch (ControlOperationRetryableConflict $exception) {
            self::assertSame('fencing_conflict', $exception->conflict);
        }
        $project->refresh();
        self::assertSame($fixture['control_oid'], $project->control_oid);
        self::assertSame(0, $project->control_generation);
        self::assertSame(['stale' => false, 'reasons' => []], $freshness->for($project, $readModel->refresh()));
        self::assertSame($generatedAt, $readModel->generated_at->toJSON());

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ticket_read_model_branch_rollback
            BEFORE INSERT ON control_branch_audit_entries
            BEGIN
                SELECT RAISE(ABORT, 'forced ticket-read-model branch rollback');
            END
            SQL);
        try {
            $this->app->make(ControlBranchChanger::class)->advance($operation->refresh(), $winningToken);
            self::fail('The forced branch transaction failure did not roll back.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS ticket_read_model_branch_rollback');
        }
        $project->refresh();
        self::assertSame('refs/heads/main', $project->control_branch);
        self::assertSame($fixture['control_oid'], $project->control_oid);
        self::assertSame(0, $project->control_generation);
        self::assertSame(['stale' => false, 'reasons' => []], $freshness->for($project, $readModel->refresh()));

        self::assertTrue($this->app->make(ControlBranchChanger::class)->advance($operation->refresh(), $winningToken));
        $project->refresh();
        self::assertSame('refs/heads/next', $project->control_branch);
        self::assertSame(1, $project->control_generation);
        self::assertContains('control_generation_mismatch', $freshness->for($project, $readModel->refresh())['reasons']);
        self::assertSame($generatedAt, $readModel->generated_at->toJSON());
    }

    /** @return array{repository: string, control_oid: string, blob_sha: string} */
    private function configureRepository(Project $project): array
    {
        $gitBinary = (new ExecutableFinder)->find('git');
        $executablePath = getenv('PATH');
        if (! is_string($gitBinary) || ! is_string($executablePath)) {
            self::markTestSkipped('Git is required for the ticket-refresh integration fixture.');
        }
        $gitBinary = realpath($gitBinary);
        if (! is_string($gitBinary)) {
            self::markTestSkipped('The Git binary is not canonical.');
        }

        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-refresh-'.bin2hex(random_bytes(8));
        $this->fixtureRoot = $root;
        self::assertTrue(mkdir($root, 0700, true));
        $source = $root.DIRECTORY_SEPARATOR.'source';
        self::assertTrue(mkdir($source, 0700));
        $init = $this->git(['init', '--object-format=sha256', '--initial-branch=main'], $source, allowFailure: true);
        if ($init === null) {
            self::markTestSkipped('The installed Git runtime does not support SHA-256 repositories.');
        }
        $this->git(['config', 'user.email', 'refresh@example.test'], $source);
        $this->git(['config', 'user.name', 'Refresh Fixture'], $source);
        self::assertTrue(mkdir($source.DIRECTORY_SEPARATOR.'tickets'.DIRECTORY_SEPARATOR.'subdir', 0700, true));
        self::assertTrue(mkdir($source.DIRECTORY_SEPARATOR.'.ai6', 0700));
        self::assertNotFalse(file_put_contents($source.DIRECTORY_SEPARATOR.'tickets'.DIRECTORY_SEPARATOR.'AI6-006F.md', "title\npassword=hunter2\n"));
        self::assertNotFalse(file_put_contents($source.DIRECTORY_SEPARATOR.'tickets'.DIRECTORY_SEPARATOR.'invalid-utf8.md', "\xC3\x28"));
        self::assertNotFalse(file_put_contents($source.DIRECTORY_SEPARATOR.'tickets'.DIRECTORY_SEPARATOR.'subdir'.DIRECTORY_SEPARATOR.'nested.md', "nested\n"));
        self::assertNotFalse(file_put_contents($source.DIRECTORY_SEPARATOR.'.ai6'.DIRECTORY_SEPARATOR.'config.yaml', "refresh_base_path: .ai6\n"));
        $this->git(['add', 'tickets/AI6-006F.md', 'tickets/invalid-utf8.md', 'tickets/subdir/nested.md', '.ai6/config.yaml'], $source);
        $symlinkBlob = trim((string) $this->git(['hash-object', '-w', '--stdin'], $source, "target\n"));
        $this->git(['update-index', '--add', '--cacheinfo', '120000,'.$symlinkBlob.',tickets/link.md'], $source);
        $this->git(['commit', '-m', 'refresh fixture'], $source);
        $controlOid = trim((string) $this->git(['rev-parse', 'HEAD'], $source));
        $blobSha = trim((string) $this->git(['rev-parse', 'HEAD:tickets/AI6-006F.md'], $source));

        $identifier = (string) $project->project_identifier;
        $repository = $root.DIRECTORY_SEPARATOR.'projects'.DIRECTORY_SEPARATOR.$identifier.DIRECTORY_SEPARATOR.'repository';
        self::assertTrue(mkdir(dirname($repository), 0700, true));
        $this->git(['clone', '--bare', '--no-local', $source, $repository], $root);

        $gitHome = $root.DIRECTORY_SEPARATOR.'git-home';
        $xdg = $gitHome.DIRECTORY_SEPARATOR.'xdg';
        $hooks = $root.DIRECTORY_SEPARATOR.'hooks';
        self::assertTrue(mkdir($xdg, 0700, true));
        self::assertTrue(mkdir($gitHome.DIRECTORY_SEPARATOR.'cache', 0700));
        self::assertTrue(mkdir($hooks, 0555));
        $globalConfig = $gitHome.DIRECTORY_SEPARATOR.'gitconfig';
        self::assertNotFalse(file_put_contents($globalConfig, "[credential]\n\thelper =\n"));
        if (DIRECTORY_SEPARATOR === '/') {
            chmod($gitHome, 0700);
            chmod($xdg, 0700);
            chmod($hooks, 0555);
            chmod($globalConfig, 0444);
        }

        $operationConfiguration = new ControlOperationConfiguration(
            $root,
            $root.DIRECTORY_SEPARATOR.'deploy-keys',
            '/usr/bin/ssh-keygen',
            base_path('app/AI6/Git/generate-deploy-key.sh'),
            120,
            30,
            30,
            3,
            $root.DIRECTORY_SEPARATOR.'known_hosts',
            ['refs/heads/main', 'refs/heads/next'],
            300,
            8,
            'tickets',
        );
        $gitConfiguration = new GitConfiguration(
            $gitBinary,
            $gitBinary,
            $executablePath,
            $gitBinary,
            (string) realpath($gitHome),
            (string) realpath($xdg),
            (string) realpath($globalConfig),
            (string) realpath($hooks),
            [],
            [],
            ['refs/heads/*'],
            [],
        );
        $runner = new HardenedGitRunner(
            $this->app->make(ControlProcessRunner::class),
            new GitRemotePolicy($gitConfiguration, new KnownHostsVerifier),
            new HardenedGitEnvironment($gitConfiguration),
        );
        $lease = new ProjectOperationLease($operationConfiguration);
        $this->app->instance(ControlOperationConfiguration::class, $operationConfiguration);
        $this->app->instance(ProjectOperationLease::class, $lease);
        $this->app->instance(ManagedProjectPath::class, new ManagedProjectPath($operationConfiguration));
        $this->app->instance(HardenedGitRunner::class, $runner);
        foreach ([QueueTicketReadModelRefresh::class, TicketReadModelRefresher::class] as $service) {
            $this->app->forgetInstance($service);
        }

        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/test-key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => $controlOid,
        ])->save();

        return ['repository' => (string) realpath($repository), 'control_oid' => $controlOid, 'blob_sha' => $blobSha];
    }

    private function refreshPath(User $administrator, Project $project, string $relativePath): TicketReadModel
    {
        $operation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            $relativePath,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $token = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('8', 32));
        self::assertIsInt($token);
        $refresher = $this->app->make(TicketReadModelRefresher::class);
        self::assertTrue($refresher->advance($operation->refresh(), $token));
        self::assertTrue($refresher->advance($operation->refresh(), $token));

        return TicketReadModel::query()->where('relative_path', $relativePath)->sole();
    }

    private function stepUpRequest(User $administrator): Request
    {
        $session = new Store('ticket-read-model-branch-test', new ArraySessionHandler(120));
        $session->setId('ticket-read-model-branch-'.bin2hex(random_bytes(8)));
        $session->start();
        $request = Request::create('/projects/control-branch', 'POST');
        $request->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied(
            $request,
            $administrator,
            QueueControlBranchChange::STEP_UP_ACTION,
        );

        return $request;
    }

    /** @param list<string> $arguments */
    private function git(
        array $arguments,
        string $directory,
        ?string $input = null,
        bool $allowFailure = false,
    ): ?string {
        $process = new Process(['git', ...$arguments], $directory, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ], $input);
        $process->run();
        if (! $process->isSuccessful()) {
            if ($allowFailure) {
                return null;
            }

            self::fail($process->getErrorOutput());
        }

        return $process->getOutput();
    }

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            (new Filesystem)->deleteDirectory($this->fixtureRoot);
        }

        parent::tearDown();
    }
}
