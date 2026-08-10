<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueControlBranchChange;
use App\AI6\Git\Actions\QueueDeployKeyProvisioning;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationConflict;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationReconciler;
use App\AI6\Git\ControlOperationRecoveryProcessor;
use App\AI6\Git\ControlOperationRetryableConflict;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\GitConfiguration;
use App\AI6\Git\GitRemotePolicy;
use App\AI6\Git\HardenedGitEnvironment;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\KnownHostsVerifier;
use App\AI6\Git\ManagedCloneSynchronizer;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Git\Models\ControlOperationRecoveryDecision;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Git\RecoveryDecisionType;
use App\AI6\Projects\Models\ControlBranchAuditEntry;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\EffectLock;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class ManagedCloneSynchronizerTest extends ControlOperationTestCase
{
    public function test_control_branch_probe_publish_and_fetch_consume_the_exact_pending_binding(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The control-branch probe and managed-fetch proof requires the Linux runtime.');
        }

        $fixture = $this->managedFixture();
        $administrator = $fixture['administrator'];
        $project = $fixture['project'];
        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);
        self::assertSame($fixture['first_oid'], $project->refresh()->control_oid);

        $missing = $this->app->make(QueueControlBranchChange::class)->handle(
            $this->stepUpRequest($administrator),
            $administrator,
            $project,
            'refs/heads/missing',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($missing->id);
        self::assertSame(ControlOperationState::FAILED, $missing->refresh()->state);
        self::assertSame('refs/heads/main', $project->refresh()->control_branch);
        self::assertSame($fixture['first_oid'], $project->control_oid);
        self::assertNull($project->pending_control_oid);
        self::assertSame(0, $project->control_generation);
        self::assertSame(0, ControlBranchAuditEntry::query()->count());

        $this->git(['checkout', '-b', 'next'], $fixture['source']);
        self::assertNotFalse(file_put_contents($fixture['source'].'/ticket.md', "next\n"));
        $this->git(['add', 'ticket.md'], $fixture['source']);
        $this->git(['commit', '-m', 'next'], $fixture['source']);
        $nextOid = trim($this->git(['rev-parse', 'HEAD'], $fixture['source']));
        $this->git(['push', $fixture['remote'], 'refs/heads/next:refs/heads/next'], $fixture['source']);

        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $project->project_identifier),
        );
        $metadataBeforeProbe = $this->protectedMetadataSnapshot($repository);

        $request = $this->stepUpRequest($administrator);
        $change = $this->app->make(QueueControlBranchChange::class)->handle(
            $request,
            $administrator,
            $project->refresh(),
            'refs/heads/next',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($change->id);
        $this->app->make(ControlOperationExecutor::class)->execute($change->id);

        $project->refresh();
        self::assertSame('refs/heads/next', $project->control_branch);
        self::assertNull($project->control_oid);
        self::assertSame('refs/heads/next', $project->pending_control_ref);
        self::assertSame($nextOid, $project->pending_control_oid);
        self::assertSame($change->id, $project->pending_control_operation_id);
        self::assertSame(1, $project->control_generation);
        self::assertSame(1, ControlBranchAuditEntry::query()->count());
        self::assertSame($metadataBeforeProbe, $this->protectedMetadataSnapshot($repository));

        $expectedCommitOperationId = (string) Str::uuid();
        try {
            $this->app->make(QueueDeployKeyProvisioning::class)->handle(
                $administrator,
                $project,
                $expectedCommitOperationId,
            );
            self::fail('An expected-commit operation was queued before the pending binding was consumed.');
        } catch (ControlOperationConflict $exception) {
            self::assertStringContainsString('pending binding', $exception->getMessage());
        }

        self::assertNotFalse(file_put_contents($fixture['source'].'/ticket.md', "moved after branch decision\n"));
        $this->git(['commit', '-am', 'moved after branch decision'], $fixture['source']);
        $movedOid = trim($this->git(['rev-parse', 'HEAD'], $fixture['source']));
        $this->git(['push', $fixture['remote'], 'HEAD:refs/heads/moved'], $fixture['source']);
        $this->git(
            ['--git-dir='.$fixture['remote'], 'update-ref', 'refs/heads/next', $movedOid, $nextOid],
            $fixture['root'],
        );

        $movedFetch = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project,
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($movedFetch->id);
        $movedFetch->refresh();
        self::assertSame(ControlOperationState::FAILED, $movedFetch->state);
        self::assertSame(
            hash('sha256', "AI6-CONTROL-RESULT-V1\0".$movedFetch->id.$movedFetch->request_hash.'pending_control_oid_mismatch'),
            $movedFetch->result()->firstOrFail()->result_binding,
        );
        $project->refresh();
        self::assertNull($project->control_oid);
        self::assertSame('refs/heads/next', $project->pending_control_ref);
        self::assertSame($nextOid, $project->pending_control_oid);
        self::assertSame($change->id, $project->pending_control_operation_id);
        self::assertSame(2, $project->control_binding_version);

        $this->git(
            ['--git-dir='.$fixture['remote'], 'update-ref', 'refs/heads/next', $nextOid, $movedOid],
            $fixture['root'],
        );
        $wrapper = $fixture['root'].'/ssh-wrapper';
        $originalWrapper = file_get_contents($wrapper);
        self::assertIsString($originalWrapper);
        self::assertTrue(chmod($wrapper, 0700));
        $raceWrapper = str_replace(
            ['__REMOTE__', '__COUNTER__', '__EXPECTED__', '__MOVED__'],
            [
                escapeshellarg((string) realpath($fixture['remote'])),
                escapeshellarg($fixture['root'].'/pending-head-counter'),
                escapeshellarg($nextOid),
                escapeshellarg($movedOid),
            ],
            <<<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 2 ]
[ "$1" = "git@git.fixture.test" ]
[ "$2" = "git-upload-pack 'acme/control.git'" ]
count=0
if [ -f __COUNTER__ ]; then count=$(cat __COUNTER__); fi
count=$((count + 1))
printf '%s\n' "$count" > __COUNTER__
if [ "$count" -eq 2 ]; then
    git --git-dir=__REMOTE__ update-ref refs/heads/next __MOVED__ __EXPECTED__
fi
exec git-upload-pack __REMOTE__
SH,
        );
        self::assertNotFalse(file_put_contents($wrapper, $raceWrapper));
        self::assertTrue(chmod($wrapper, 0555));

        $racingFetch = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project->refresh(),
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($racingFetch->id);
        $this->git(
            ['--git-dir='.$fixture['remote'], 'update-ref', 'refs/heads/next', $nextOid, $movedOid],
            $fixture['root'],
        );
        $racingFetch->refresh();
        self::assertSame(ControlOperationState::FAILED, $racingFetch->state);
        self::assertSame(
            hash('sha256', "AI6-CONTROL-RESULT-V1\0".$racingFetch->id.$racingFetch->request_hash.'pending_control_head_mismatch'),
            $racingFetch->result()->firstOrFail()->result_binding,
        );
        $project->refresh();
        self::assertNull($project->control_oid);
        self::assertSame('refs/heads/next', $project->pending_control_ref);
        self::assertSame($nextOid, $project->pending_control_oid);
        self::assertSame($change->id, $project->pending_control_operation_id);
        self::assertSame(2, $project->control_binding_version);

        self::assertTrue(chmod($wrapper, 0700));
        self::assertNotFalse(file_put_contents($wrapper, $originalWrapper));
        self::assertTrue(chmod($wrapper, 0555));

        $fetch = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project,
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        self::assertNull($fetch->expected_control_commit);
        $this->app->make(ControlOperationExecutor::class)->execute($fetch->id);

        $project->refresh();
        self::assertSame('refs/heads/next', $project->control_branch);
        self::assertSame($nextOid, $project->control_oid);
        self::assertNull($project->pending_control_ref);
        self::assertNull($project->pending_control_oid);
        self::assertNull($project->pending_control_operation_id);
        self::assertSame(3, $project->control_binding_version);
        self::assertSame(1, $project->control_generation);

        $project->forceFill(['provisioning_status' => ProjectProvisioningStatus::NOT_PROVISIONED])->save();
        $expectedCommitOperation = $this->app->make(QueueDeployKeyProvisioning::class)->handle(
            $administrator,
            $project->refresh(),
            $expectedCommitOperationId,
        );
        self::assertSame($nextOid, $expectedCommitOperation->expected_control_commit);
    }

    public function test_clone_then_fetch_publish_only_the_bound_control_ref_and_binding(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The managed-clone process and effect-lock proof requires the Linux runtime.');
        }

        $fixture = $this->managedFixture();
        $administrator = $fixture['administrator'];
        $project = $fixture['project'];
        $root = $fixture['root'];
        $remote = $fixture['remote'];
        $source = $fixture['source'];
        $firstOid = $fixture['first_oid'];
        $paths = $fixture['paths'];
        $runner = $fixture['runner'];
        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);

        $project->refresh();
        $clone->refresh();
        self::assertTrue($clone->state->terminal());
        self::assertSame('completed', $clone->state->value);
        self::assertSame($firstOid, $project->control_oid);
        self::assertSame(1, $project->control_binding_version);
        $repository = $paths->assertRepository($paths->repositoryDirectory((string) $project->project_identifier));
        self::assertSame(['refs/heads/main' => $firstOid], $runner->refs($repository, $this->context($project->getKey(), $clone->id)));
        self::assertDirectoryDoesNotExist($root.'/.control-staging/'.$clone->id);
        $protectedMetadata = $this->protectedMetadataSnapshot($repository);

        file_put_contents($source.'/ticket.md', "second\n");
        $this->git(['commit', '-am', 'second'], $source);
        $secondOid = trim($this->git(['rev-parse', 'HEAD'], $source));
        $this->git(['push', $remote, 'refs/heads/main:refs/heads/main'], $source);
        $fetch = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project->refresh(),
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($fetch->id);

        $project->refresh();
        $fetch->refresh();
        self::assertSame('completed', $fetch->state->value);
        self::assertSame($secondOid, $project->control_oid);
        self::assertSame(2, $project->control_binding_version);
        self::assertFileDoesNotExist($repository.'/FETCH_HEAD');
        self::assertSame(['refs/heads/main' => $secondOid], $runner->refs($repository, $this->context($project->getKey(), $fetch->id)));
        self::assertSame($protectedMetadata, $this->protectedMetadataSnapshot($repository));
        self::assertDirectoryDoesNotExist($root.'/.control-staging/'.$fetch->id);

        $this->git(['fetch', $remote, 'refs/heads/main'], $repository);
        self::assertFileExists($repository.'/FETCH_HEAD');
        self::assertNotSame($protectedMetadata, $this->protectedMetadataSnapshot($repository));
    }

    #[DataProvider('sagaCrashPhases')]
    public function test_clone_saga_reconciles_each_cross_storage_crash_boundary(ControlOperationPhase $crashPhase): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The managed-clone saga crash proof requires the Linux runtime.');
        }

        $fixture = $this->managedFixture();
        $administrator = $fixture['administrator'];
        $project = $fixture['project'];
        $lease = $fixture['lease'];
        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $administrator,
            $project->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $attemptToken = $lease->claim($operation, str_repeat('1', 32));
        self::assertIsInt($attemptToken);
        $synchronizer = $this->app->make(ManagedCloneSynchronizer::class);
        for ($step = 0; $step < 4 && $operation->refresh()->phase !== $crashPhase; $step++) {
            self::assertFalse($synchronizer->advance($operation, $attemptToken));
        }
        self::assertSame($crashPhase, $operation->refresh()->phase);
        if ($crashPhase === ControlOperationPhase::EFFECT_STAGED) {
            self::assertDirectoryDoesNotExist(
                $fixture['paths']->repositoryDirectory((string) $project->project_identifier),
            );
        }
        self::assertTrue($lease->expire($operation->id, $project->getKey(), $attemptToken));

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame(ControlOperationPhase::ATTEMPT_COMPLETED, $operation->phase);
        self::assertSame($fixture['first_oid'], $project->control_oid);
        self::assertSame(1, $project->control_binding_version);
        self::assertSame(1, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
        self::assertNull($project->operation_lock_operation_id);
        self::assertDirectoryDoesNotExist($fixture['root'].'/.control-staging/'.$operation->id);
        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $project->project_identifier),
        );
        self::assertSame(
            ['refs/heads/main' => $fixture['first_oid']],
            $fixture['runner']->refs($repository, $this->context($project->getKey(), $operation->id)),
        );
    }

    #[DataProvider('sagaCrashPhases')]
    public function test_fetch_saga_reconciles_each_cross_storage_crash_boundary(ControlOperationPhase $crashPhase): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The managed-fetch saga crash proof requires the Linux runtime.');
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
        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $project->refresh()->project_identifier),
        );
        $protectedMetadata = $this->protectedMetadataSnapshot($repository);

        self::assertNotFalse(file_put_contents($fixture['source'].'/ticket.md', "fetch crash target\n"));
        $this->git(['commit', '-am', 'fetch crash target'], $fixture['source']);
        $targetOid = trim($this->git(['rev-parse', 'HEAD'], $fixture['source']));
        $this->git(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);
        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $project->refresh(),
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('4', 32));
        self::assertIsInt($attemptToken);
        $synchronizer = $this->app->make(ManagedCloneSynchronizer::class);
        for ($step = 0; $step < 4 && $operation->refresh()->phase !== $crashPhase; $step++) {
            self::assertFalse($synchronizer->advance($operation, $attemptToken));
        }
        self::assertSame($crashPhase, $operation->refresh()->phase);

        if ($crashPhase === ControlOperationPhase::EFFECT_STAGED) {
            $stagedRefs = $fixture['runner']->refs(
                $repository,
                $this->context($project->getKey(), $operation->id),
            );
            self::assertSame(
                $fixture['first_oid'],
                $stagedRefs['refs/heads/main'],
            );
            self::assertSame($targetOid, $stagedRefs[ManagedProjectPath::attemptRef($operation->id, $attemptToken)]);
            self::assertCount(2, $stagedRefs);
            self::assertSame($fixture['first_oid'], $project->refresh()->control_oid);
        }
        self::assertTrue($fixture['lease']->expire($operation->id, $project->getKey(), $attemptToken));

        $this->app->make(ControlOperationExecutor::class)->execute($operation->id);

        $operation->refresh();
        $project->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame(ControlOperationPhase::ATTEMPT_COMPLETED, $operation->phase);
        self::assertSame($targetOid, $project->control_oid);
        self::assertSame(2, $project->control_binding_version);
        self::assertSame(1, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
        self::assertNull($project->operation_lock_operation_id);
        self::assertDirectoryDoesNotExist($fixture['root'].'/.control-staging/'.$operation->id);
        self::assertSame(
            ['refs/heads/main' => $targetOid],
            $fixture['runner']->refs($repository, $this->context($project->getKey(), $operation->id)),
        );
        self::assertSame($protectedMetadata, $this->protectedMetadataSnapshot($repository));
    }

    /** @return iterable<string, array{ControlOperationPhase}> */
    public static function sagaCrashPhases(): iterable
    {
        yield 'effect staged before filesystem publish' => [ControlOperationPhase::EFFECT_STAGED];
        yield 'filesystem published before sqlite binding' => [ControlOperationPhase::OUTCOME_PUBLISHED];
        yield 'sqlite binding finalized before cleanup' => [ControlOperationPhase::BINDING_FINALIZED];
    }

    public function test_remote_probe_mismatch_never_publishes_a_usable_clone(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The two-step remote-probe binding proof requires the Linux runtime.');
        }

        $fixture = $this->managedFixture();
        $source = $fixture['source'];
        self::assertNotFalse(file_put_contents($source.'/ticket.md', "second\n"));
        $this->git(['commit', '-am', 'second'], $source);
        $secondOid = trim($this->git(['rev-parse', 'HEAD'], $source));
        $this->git(['push', $fixture['remote'], 'HEAD:refs/heads/alternate'], $source);

        $wrapper = $fixture['root'].'/ssh-wrapper';
        self::assertTrue(chmod($wrapper, 0700));
        $script = str_replace(
            ['__REMOTE__', '__COUNTER__', '__FIRST__', '__SECOND__'],
            [
                escapeshellarg((string) realpath($fixture['remote'])),
                escapeshellarg($fixture['root'].'/ssh-counter'),
                escapeshellarg($fixture['first_oid']),
                escapeshellarg($secondOid),
            ],
            <<<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 2 ]
[ "$1" = "git@git.fixture.test" ]
[ "$2" = "git-upload-pack 'acme/control.git'" ]
count=0
if [ -f __COUNTER__ ]; then count=$(cat __COUNTER__); fi
count=$((count + 1))
printf '%s\n' "$count" > __COUNTER__
if [ $((count % 2)) -eq 0 ]; then
    current=$(git --git-dir=__REMOTE__ rev-parse refs/heads/main)
    if [ "$current" = __FIRST__ ]; then target=__SECOND__; else target=__FIRST__; fi
    git --git-dir=__REMOTE__ update-ref refs/heads/main "$target" "$current"
fi
exec git-upload-pack __REMOTE__
SH,
        );
        self::assertNotFalse(file_put_contents($wrapper, $script));
        self::assertTrue(chmod($wrapper, 0555));

        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $this->app->make(ControlOperationExecutor::class)->execute($operation->id);
                if ($attempt < 3) {
                    self::fail('A probe/clone OID mismatch did not remain retryable.');
                }
            } catch (\RuntimeException $exception) {
                self::assertLessThan(3, $attempt);
                self::assertSame('The control operation attempt failed and remains retryable.', $exception->getMessage());
            }
        }

        $operation->refresh();
        $project = $fixture['project']->refresh();
        self::assertSame(ControlOperationState::FAILED, $operation->state);
        self::assertNull($project->control_oid);
        self::assertSame(0, $project->control_binding_version);
        self::assertNull($project->operation_lock_operation_id);
        self::assertDirectoryDoesNotExist($fixture['paths']->repositoryDirectory((string) $project->project_identifier));
        self::assertDirectoryDoesNotExist($fixture['root'].'/.control-staging/'.$operation->id);
    }

    public function test_binding_version_conflict_stays_visible_until_bound_recovery_reconciles_it(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The managed-clone recovery proof requires the Linux runtime.');
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

        self::assertNotFalse(file_put_contents($fixture['source'].'/ticket.md', "recovery target\n"));
        $this->git(['commit', '-am', 'recovery target'], $fixture['source']);
        $targetOid = trim($this->git(['rev-parse', 'HEAD'], $fixture['source']));
        $this->git(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);
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
        self::assertNull($project->operation_lock_operation_id);
        self::assertSame(1, ControlOperationResult::query()->where('control_operation_id', $fetch->id)->count());
    }

    public function test_clone_takeover_removes_the_superseded_attempt_tree_before_restaging(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('The managed-clone takeover cleanup proof requires the Linux runtime.');
        }

        $fixture = $this->managedFixture();
        $project = $fixture['project'];
        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $project->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $oldAttemptToken = $fixture['lease']->claim($operation, str_repeat('1', 32));
        self::assertIsInt($oldAttemptToken);
        $staleRepository = $fixture['paths']->stagedRepository(
            (string) $project->project_identifier,
            $operation->id,
            $oldAttemptToken,
        );
        self::assertTrue(mkdir($staleRepository));
        self::assertNotFalse(file_put_contents($staleRepository.'/partial-clone', "stale\n"));
        DB::table('control_operations')->where('id', $operation->id)->update([
            'phase' => ControlOperationPhase::LAUNCH_INTENT->value,
            'effect_attempt_token' => $oldAttemptToken,
            'target_control_oid' => $fixture['first_oid'],
            'launch_argument_hash' => str_repeat('a', 64),
            'version' => DB::raw('version + 1'),
        ]);

        self::assertTrue($fixture['lease']->expire($operation->id, $project->getKey(), $oldAttemptToken));
        $newAttemptToken = $fixture['lease']->claim($operation->refresh(), str_repeat('2', 32));
        self::assertIsInt($newAttemptToken);
        self::assertGreaterThan($oldAttemptToken, $newAttemptToken);

        self::assertFalse($this->app->make(ManagedCloneSynchronizer::class)->advance(
            $operation->refresh(),
            $newAttemptToken,
        ));

        self::assertDirectoryDoesNotExist(dirname($staleRepository));
        self::assertDirectoryExists($fixture['paths']->stagedRepository(
            (string) $project->project_identifier,
            $operation->id,
            $newAttemptToken,
        ));
        self::assertSame(ControlOperationPhase::EFFECT_STAGED, $operation->refresh()->phase);
        self::assertSame($newAttemptToken, $operation->effect_attempt_token);
    }

    public function test_clone_publish_rechecks_lease_after_waiting_for_the_effect_lock(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('The managed-clone publish-lock fencing proof requires Linux and pcntl.');
        }
        $this->useForkSafeDatabase();
        $fixture = $this->managedFixture();
        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $attemptToken = $fixture['lease']->claim($operation, str_repeat('3', 32));
        self::assertIsInt($attemptToken);
        self::assertFalse($this->app->make(ManagedCloneSynchronizer::class)->advance($operation, $attemptToken));
        self::assertSame(ControlOperationPhase::EFFECT_STAGED, $operation->refresh()->phase);

        $resultFile = $fixture['root'].'/publish-fencing-result';
        $startFile = $fixture['root'].'/publish-fencing-start';
        $child = pcntl_fork();
        self::assertNotSame(-1, $child);
        if ($child === 0) {
            DB::purge('sqlite');
            try {
                while (! is_file($startFile)) {
                    usleep(1_000);
                }
                $this->app->make(ManagedCloneSynchronizer::class)->advance($operation->refresh(), $attemptToken);
                file_put_contents($resultFile, 'unexpected-success');
                exit(71);
            } catch (ControlOperationRetryableConflict $exception) {
                file_put_contents($resultFile, $exception->conflict);
                exit($exception->conflict === 'lease_lost' ? 0 : 72);
            } catch (\Throwable $exception) {
                file_put_contents($resultFile, $exception::class);
                exit(73);
            }
        }
        DB::purge('sqlite');
        $held = $this->app->make(EffectLock::class)->acquire('lock-0001', 1);
        self::assertNotNull($held->handle);
        file_put_contents($startFile, 'start');

        $effectLockReleased = false;
        try {
            usleep(50_000);
            self::assertTrue($fixture['lease']->expire($operation->id, $fixture['project']->getKey(), $attemptToken));
            $held->handle->release();
            $effectLockReleased = true;
            pcntl_waitpid($child, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status), (string) @file_get_contents($resultFile));
            self::assertSame('lease_lost', file_get_contents($resultFile));
            self::assertDirectoryDoesNotExist(
                $fixture['paths']->repositoryDirectory((string) $fixture['project']->refresh()->project_identifier),
            );
            self::assertNull($fixture['project']->control_oid);
            self::assertSame(ControlOperationPhase::EFFECT_STAGED, $operation->refresh()->phase);
        } finally {
            if (! $effectLockReleased) {
                $held->handle->release();
            }
            if ($child > 0) {
                pcntl_waitpid($child, $status, WNOHANG);
            }
        }
    }

    public function test_fetch_takeover_serializes_processes_and_fences_the_old_attempt(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('The managed-fetch takeover proof requires Linux and pcntl.');
        }
        $this->useForkSafeDatabase();
        $fixture = $this->managedFixture();
        $clone = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_CLONE,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $this->app->make(ControlOperationExecutor::class)->execute($clone->id);

        self::assertNotFalse(file_put_contents($fixture['source'].'/ticket.md', "takeover target\n"));
        $this->git(['commit', '-am', 'takeover target'], $fixture['source']);
        $targetOid = trim($this->git(['rev-parse', 'HEAD'], $fixture['source']));
        $this->git(['push', $fixture['remote'], 'refs/heads/main:refs/heads/main'], $fixture['source']);

        $counterFile = $fixture['root'].'/takeover-counter';
        $oldStartedFile = $fixture['root'].'/takeover-old-started';
        $releaseOldFile = $fixture['root'].'/takeover-release-old';
        $oldResultFile = $fixture['root'].'/takeover-old-result';
        $newStagedFile = $fixture['root'].'/takeover-new-staged';
        $releasePublishFile = $fixture['root'].'/takeover-release-publish';
        $newResultFile = $fixture['root'].'/takeover-new-result';
        $wrapper = $fixture['root'].'/ssh-wrapper';
        self::assertTrue(chmod($wrapper, 0700));
        $script = str_replace(
            ['__REMOTE__', '__COUNTER__', '__OLD_STARTED__', '__RELEASE_OLD__'],
            [
                escapeshellarg((string) realpath($fixture['remote'])),
                escapeshellarg($counterFile),
                escapeshellarg($oldStartedFile),
                escapeshellarg($releaseOldFile),
            ],
            <<<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 2 ]
[ "$1" = "git@git.fixture.test" ]
[ "$2" = "git-upload-pack 'acme/control.git'" ]
count=0
if [ -f __COUNTER__ ]; then count=$(cat __COUNTER__); fi
count=$((count + 1))
printf '%s\n' "$count" > __COUNTER__
if [ "$count" -eq 2 ]; then
    printf 'started\n' > __OLD_STARTED__
    while [ ! -f __RELEASE_OLD__ ]; do sleep 0.01; done
fi
exec git-upload-pack __REMOTE__
SH,
        );
        self::assertNotFalse(file_put_contents($wrapper, $script));
        self::assertTrue(chmod($wrapper, 0555));

        $operation = $this->app->make(QueueManagedCloneOperation::class)->handle(
            $fixture['administrator'],
            $fixture['project']->refresh(),
            ControlOperationType::MANAGED_FETCH,
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $oldAttemptToken = $fixture['lease']->claim($operation, 'fetch-takeover-old');
        self::assertIsInt($oldAttemptToken);
        $oldChild = pcntl_fork();
        self::assertNotSame(-1, $oldChild);
        if ($oldChild === 0) {
            DB::purge('sqlite');
            try {
                $this->app->make(ManagedCloneSynchronizer::class)->advance($operation->refresh(), $oldAttemptToken);
                file_put_contents($oldResultFile, 'unexpected-success');
                exit(81);
            } catch (ControlOperationRetryableConflict $exception) {
                file_put_contents($oldResultFile, $exception->conflict);
                exit($exception->conflict === 'lease_lost' ? 0 : 82);
            } catch (\Throwable $exception) {
                file_put_contents($oldResultFile, $exception::class);
                exit(83);
            }
        }
        DB::purge('sqlite');
        $this->waitForFixtureFile($oldStartedFile);
        self::assertSame(ControlOperationPhase::PROCESS_STARTED, $operation->refresh()->phase);
        self::assertTrue($fixture['lease']->expire($operation->id, $fixture['project']->getKey(), $oldAttemptToken));
        self::assertSame(1, $this->app->make(ControlOperationReconciler::class)->reconcile());
        DB::table('jobs')->delete();
        $newAttemptToken = $fixture['lease']->claim($operation->refresh(), 'fetch-takeover-new');
        self::assertIsInt($newAttemptToken);
        self::assertGreaterThan($oldAttemptToken, $newAttemptToken);

        $newChild = pcntl_fork();
        self::assertNotSame(-1, $newChild);
        if ($newChild === 0) {
            DB::purge('sqlite');
            try {
                $current = $operation->refresh();
                if ($this->app->make(ManagedCloneSynchronizer::class)->advance($current, $newAttemptToken)) {
                    file_put_contents($newResultFile, 'unexpected-terminal-stage');
                    exit(84);
                }
                $current->refresh();
                file_put_contents($newStagedFile, $current->phase->value);
                while (! is_file($releasePublishFile)) {
                    usleep(1_000);
                }
                for ($step = 0; $step < 4; $step++) {
                    if ($this->app->make(ManagedCloneSynchronizer::class)->advance($current, $newAttemptToken)) {
                        file_put_contents($newResultFile, 'completed');
                        exit(0);
                    }
                    $current->refresh();
                }
                file_put_contents($newResultFile, 'transition-budget');
                exit(85);
            } catch (\Throwable $exception) {
                file_put_contents($newResultFile, $exception::class.':'.$exception->getMessage());
                exit(86);
            }
        }
        DB::purge('sqlite');

        usleep(100_000);
        self::assertSame(2, (int) trim((string) file_get_contents($counterFile)));
        self::assertSame(ControlOperationPhase::PROCESS_STARTED, $operation->refresh()->phase);
        self::assertSame($oldAttemptToken, $operation->effect_attempt_token);
        $blockedRepository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $fixture['project']->refresh()->project_identifier),
        );
        self::assertArrayNotHasKey(
            ManagedProjectPath::attemptRef($operation->id, $newAttemptToken),
            $fixture['runner']->refs(
                $blockedRepository,
                $this->context($fixture['project']->getKey(), $operation->id),
            ),
        );

        self::assertNotFalse(file_put_contents($releaseOldFile, 'release'));
        $this->waitForFixtureFile($oldResultFile);
        $this->waitForFixtureFile($newStagedFile);
        pcntl_waitpid($oldChild, $oldStatus);
        self::assertTrue(pcntl_wifexited($oldStatus));
        self::assertSame(0, pcntl_wexitstatus($oldStatus), (string) file_get_contents($oldResultFile));
        self::assertSame('lease_lost', file_get_contents($oldResultFile));
        self::assertSame(ControlOperationPhase::EFFECT_STAGED->value, file_get_contents($newStagedFile));
        self::assertSame(4, (int) trim((string) file_get_contents($counterFile)));
        self::assertSame($newAttemptToken, $operation->refresh()->effect_attempt_token);

        $repository = $fixture['paths']->assertRepository(
            $fixture['paths']->repositoryDirectory((string) $fixture['project']->refresh()->project_identifier),
        );
        $refs = $fixture['runner']->refs($repository, $this->context($fixture['project']->getKey(), $operation->id));
        self::assertSame($fixture['first_oid'], $refs['refs/heads/main']);
        self::assertSame($targetOid, $refs[ManagedProjectPath::attemptRef($operation->id, $oldAttemptToken)]);
        self::assertSame($targetOid, $refs[ManagedProjectPath::attemptRef($operation->id, $newAttemptToken)]);
        try {
            $this->app->make(ManagedCloneSynchronizer::class)->advance($operation->refresh(), $oldAttemptToken);
            self::fail('The superseded fetch attempt published after losing its lease.');
        } catch (ControlOperationRetryableConflict $exception) {
            self::assertSame('lease_lost', $exception->conflict);
        }

        self::assertNotFalse(file_put_contents($releasePublishFile, 'release'));
        pcntl_waitpid($newChild, $newStatus);
        self::assertTrue(pcntl_wifexited($newStatus));
        self::assertSame(0, pcntl_wexitstatus($newStatus), (string) @file_get_contents($newResultFile));
        self::assertSame('completed', file_get_contents($newResultFile));
        $operation->refresh();
        $project = $fixture['project']->refresh();
        self::assertSame(ControlOperationState::COMPLETED, $operation->state);
        self::assertSame(ControlOperationPhase::ATTEMPT_COMPLETED, $operation->phase);
        self::assertSame($targetOid, $project->control_oid);
        self::assertSame(2, $project->control_binding_version);
        self::assertSame(['refs/heads/main' => $targetOid], $fixture['runner']->refs(
            $repository,
            $this->context($project->getKey(), $operation->id),
        ));
        self::assertSame(1, ControlOperationResult::query()->where('control_operation_id', $operation->id)->count());
        self::assertNull($project->operation_lock_operation_id);
        self::assertDirectoryDoesNotExist($fixture['root'].'/.control-staging/'.$operation->id);
    }

    /**
     * @return array{
     *     administrator: User,
     *     project: Project,
     *     root: string,
     *     remote: string,
     *     source: string,
     *     first_oid: string,
     *     paths: ManagedProjectPath,
     *     runner: HardenedGitRunner,
     *     lease: ProjectOperationLease
     * }
     */
    private function managedFixture(): array
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $root = $this->configureRealWorkerRuntime($project);
        $remote = $root.'/remote.git';
        $source = $root.'/source';
        self::assertTrue(mkdir($source, 0700));
        (new Process(['git', 'init', '--object-format=sha256', '--initial-branch=main'], $source))->mustRun();
        $this->git(['config', 'user.name', 'AI6 Test'], $source);
        $this->git(['config', 'user.email', 'ai6@example.invalid'], $source);
        self::assertNotFalse(file_put_contents($source.'/ticket.md', "first\n"));
        $this->git(['add', 'ticket.md'], $source);
        $this->git(['commit', '-m', 'first'], $source);
        $firstOid = trim($this->git(['rev-parse', 'HEAD'], $source));
        self::assertTrue(mkdir($remote, 0700));
        $this->git(['init', '--bare', '--object-format=sha256'], $remote);
        $this->git(['push', $remote, 'refs/heads/main:refs/heads/main'], $source);

        $keyBytes = random_bytes(48);
        $fingerprint = 'SHA256:'.rtrim(base64_encode(hash('sha256', $keyBytes, true)), '=');
        $knownHosts = $root.'/known_hosts';
        self::assertNotFalse(file_put_contents($knownHosts, 'git.fixture.test ssh-ed25519 '.base64_encode($keyBytes)."\n"));
        self::assertTrue(chmod($knownHosts, 0444));
        $privateKey = $root.'/private-key';
        self::assertNotFalse(file_put_contents($privateKey, "test-only-key\n"));
        self::assertTrue(chmod($privateKey, 0400));
        $sshWrapper = $root.'/ssh-wrapper';
        $quotedRemote = escapeshellarg((string) realpath($remote));
        self::assertNotFalse(file_put_contents($sshWrapper, str_replace('__REMOTE__', $quotedRemote, <<<'SH'
#!/bin/sh
set -eu
[ "$#" -eq 2 ]
[ "$1" = "git@git.fixture.test" ]
[ "$2" = "git-upload-pack 'acme/control.git'" ]
exec git-upload-pack __REMOTE__
SH)));
        self::assertTrue(chmod($sshWrapper, 0555));

        $gitHome = $root.'/git-home';
        $xdg = $gitHome.'/xdg';
        $hooks = $root.'/git-hooks';
        self::assertTrue(mkdir($xdg, 0700, true));
        self::assertTrue(chmod($gitHome, 0700));
        self::assertTrue(mkdir($hooks, 0555));
        $globalConfig = $gitHome.'/gitconfig';
        self::assertNotFalse(file_put_contents($globalConfig, "[credential]\n\thelper =\n"));
        self::assertTrue(chmod($globalConfig, 0444));
        $gitBinary = (new ExecutableFinder)->find('git');
        $sshBinary = (new ExecutableFinder)->find('ssh');
        $executablePath = getenv('PATH');
        self::assertIsString($gitBinary);
        self::assertIsString($sshBinary);
        self::assertIsString($executablePath);
        $gitConfiguration = new GitConfiguration(
            (string) realpath($gitBinary),
            (string) realpath($sshBinary),
            $executablePath,
            (string) realpath($sshWrapper),
            (string) realpath($gitHome),
            (string) realpath($xdg),
            (string) realpath($globalConfig),
            (string) realpath($hooks),
            ['git.fixture.test'],
            ['acme/control.git'],
            ['refs/heads/*'],
            ['git.fixture.test' => [$fingerprint]],
        );
        $operationConfiguration = new ControlOperationConfiguration(
            $root,
            $root.'/deploy-keys',
            '/usr/bin/ssh-keygen',
            base_path('app/AI6/Git/generate-deploy-key.sh'),
            10,
            1,
            1,
            3,
            (string) realpath($knownHosts),
            ['refs/heads/main', 'refs/heads/next', 'refs/heads/missing'],
            300,
            8,
        );
        $lease = new ProjectOperationLease($operationConfiguration);
        $paths = new ManagedProjectPath($operationConfiguration);
        $policy = new GitRemotePolicy($gitConfiguration, new KnownHostsVerifier);
        $runner = new HardenedGitRunner(
            $this->app->make(ControlProcessRunner::class),
            $policy,
            new HardenedGitEnvironment($gitConfiguration),
        );
        $this->app->instance(ControlOperationConfiguration::class, $operationConfiguration);
        $this->app->instance(ProjectOperationLease::class, $lease);
        $this->app->instance(ManagedProjectPath::class, $paths);
        $this->app->instance(GitConfiguration::class, $gitConfiguration);
        $this->app->instance(GitRemotePolicy::class, $policy);
        $this->app->instance(HardenedGitRunner::class, $runner);
        foreach ([ManagedCloneSynchronizer::class, ControlOperationRecoveryProcessor::class, ControlOperationExecutor::class] as $service) {
            $this->app->forgetInstance($service);
        }

        $project->forceFill([
            'remote' => 'git@git.fixture.test:acme/control.git',
            'host_key_fingerprint' => $fingerprint,
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => (string) realpath($privateKey),
            'public_deploy_key' => "ssh-ed25519 fixture\n",
        ])->save();

        return [
            'administrator' => $administrator,
            'project' => $project,
            'root' => $root,
            'remote' => $remote,
            'source' => $source,
            'first_oid' => $firstOid,
            'paths' => $paths,
            'runner' => $runner,
            'lease' => $lease,
        ];
    }

    /** @return array<string, string> */
    private function protectedMetadataSnapshot(string $repository): array
    {
        $snapshot = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repository, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($repository) + 1));
            if ($relative === 'refs/heads/main'
                || str_starts_with($relative.'/', 'refs/ai6/')
                || str_starts_with($relative.'/', 'objects/')) {
                continue;
            }
            if ($entry->isLink()) {
                $snapshot[$relative] = 'link:'.(string) readlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                $snapshot[$relative] = 'directory';
            } elseif ($entry->isFile()) {
                $snapshot[$relative] = 'file:'.hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($snapshot);

        return $snapshot;
    }

    private function waitForFixtureFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        self::assertFileExists($path);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function context(int $projectId, string $operationId): RedactionContext
    {
        return new RedactionContext((string) $projectId, $operationId, 'managed-clone-e2e');
    }

    private function stepUpRequest(User $administrator): Request
    {
        $session = new Store('control-branch-test', new ArraySessionHandler(120));
        $session->setId('control-branch-'.bin2hex(random_bytes(8)));
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
}
