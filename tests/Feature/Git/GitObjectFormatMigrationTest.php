<?php

namespace Tests\Feature\Git;

use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueControlBranchChange;
use App\AI6\Git\Actions\QueueManagedCloneOperation;
use App\AI6\Git\ControlOperationExecutor;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\GitObjectFormat;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectProvisioningStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class GitObjectFormatMigrationTest extends ControlOperationTestCase
{
    use BuildsManagedControlRuntimeFixture;

    private const MIGRATION = '2026_09_24_000000_add_git_object_format_contract';

    public function test_pending_only_legacy_branch_binding_finishes_through_worker_fetch_after_upgrade(): void
    {
        $fixture = $this->managedFixture(GitObjectFormat::SHA256);
        $project = $fixture['project'];
        $actor = $fixture['administrator'];
        $clone = app(QueueManagedCloneOperation::class)->handle($actor, $project, ControlOperationType::MANAGED_CLONE, (string) Str::uuid());
        DB::table('jobs')->delete();
        app(ControlOperationExecutor::class)->execute($clone->id);
        self::assertSame(ControlOperationState::COMPLETED, $clone->refresh()->state);
        $this->managedFixtureGit(['checkout', '-b', 'next'], $fixture['source']);
        file_put_contents($fixture['source'].'/ticket.md', "pending upgrade target\n");
        $this->managedFixtureGit(['commit', '-am', 'pending upgrade target'], $fixture['source']);
        $next = trim($this->managedFixtureGit(['rev-parse', 'HEAD'], $fixture['source']));
        $this->managedFixtureGit(['push', $fixture['remote'], 'HEAD:refs/heads/next'], $fixture['source']);
        $session = new Store('pending-upgrade', new ArraySessionHandler(120));
        $session->start();
        $request = Request::create('/projects/control-branch', 'POST');
        $request->setLaravelSession($session);
        app(StepUpGuard::class)->markSatisfied($request, $actor, QueueControlBranchChange::STEP_UP_ACTION);
        $change = app(QueueControlBranchChange::class)->handle($request, $actor, $project->refresh(), 'refs/heads/next', (string) Str::uuid());
        DB::table('jobs')->delete();
        app(ControlOperationExecutor::class)->execute($change->id);
        self::assertSame(ControlOperationState::COMPLETED, $change->refresh()->state);
        self::assertNull($project->refresh()->control_oid);
        self::assertSame($next, $project->pending_control_oid);
        $fetch = app(QueueManagedCloneOperation::class)->handle($actor, $project, ControlOperationType::MANAGED_FETCH, (string) Str::uuid());
        DB::table('jobs')->delete();
        $before = (array) DB::table('control_operations')->where('id', $fetch->id)->first();
        $bindingVersion = $project->control_binding_version;
        $this->migrate('down');
        self::assertFalse(Schema::hasColumn('projects', 'object_format'));
        $this->migrate('up');
        self::assertSame($before, (array) DB::table('control_operations')->where('id', $fetch->id)->first());
        self::assertSame(GitObjectFormat::SHA256, $project->refresh()->object_format);
        self::assertNull($project->control_oid);
        self::assertSame($next, $project->pending_control_oid);
        app(ControlOperationExecutor::class)->execute($fetch->id);
        self::assertSame(ControlOperationState::COMPLETED, $fetch->refresh()->state);
        self::assertSame(GitObjectFormat::SHA256, $project->refresh()->object_format);
        self::assertSame($next, $project->control_oid);
        self::assertSame($bindingVersion + 1, $project->control_binding_version);
        self::assertNull($project->pending_control_oid);
        self::assertNull($project->pending_control_ref);
        self::assertNull($project->pending_control_operation_id);
        self::assertNull($project->operation_lock_operation_id);
        app(ControlOperationExecutor::class)->execute($fetch->id);
        self::assertSame($bindingVersion + 1, $project->refresh()->control_binding_version);
    }

    public function test_upgrade_backfills_only_control_or_pending_bindings_and_roundtrips_exact_guards(): void
    {
        $this->migrate('down');
        $legacyGuards = $this->guards();
        $unbound = Project::query()->create(['name' => 'Unbound']);
        $confirmed = Project::query()->create(['name' => 'Confirmed', 'control_oid' => str_repeat('a', 64)]);
        $pending = Project::query()->create(['name' => 'Pending', 'pending_control_oid' => str_repeat('b', 64), 'pending_control_ref' => 'refs/heads/next', 'pending_control_operation_id' => (string) Str::uuid()]);
        $this->migrate('up');
        self::assertNull($unbound->refresh()->object_format);
        self::assertSame(GitObjectFormat::SHA256, $confirmed->refresh()->object_format);
        self::assertSame(GitObjectFormat::SHA256, $pending->refresh()->object_format);
        $upgraded = $this->snapshot();
        $this->migrate('down');
        self::assertFalse(Schema::hasColumn('projects', 'object_format'));
        self::assertSame($legacyGuards, $this->guards());
        $this->migrate('up');
        self::assertSame($upgraded, $this->snapshot());
        self::assertSame(1, DB::table('projects')->where('id', $confirmed->id)->update(['control_oid' => str_repeat('c', 64)]));
        $this->reject(fn () => DB::table('projects')->where('id', $confirmed->id)->update(['control_oid' => str_repeat('c', 40)]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidLegacy(): iterable
    {
        foreach (['control_oid', 'pending_control_oid'] as $field) {
            foreach (['sha1' => str_repeat('a', 40), 'uppercase' => str_repeat('A', 64), 'zero' => str_repeat('0', 64), 'invalid' => str_repeat('g', 64)] as $name => $value) {
                yield $field.' '.$name => [$field, $value];
            }
        }
    }

    #[DataProvider('invalidLegacy')]
    public function test_invalid_legacy_binding_aborts_without_schema_data_or_registration_changes(string $field, string $value): void
    {
        $this->migrate('down');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $attributes = ['name' => 'Legacy', $field => $value];
        if ($field === 'pending_control_oid') {
            $attributes += ['pending_control_ref' => 'refs/heads/next', 'pending_control_operation_id' => (string) Str::uuid()];
        }
        $project = Project::query()->create($attributes);
        $before = $this->snapshot();
        try {
            Artisan::call('migrate', ['--force' => true]);
            self::fail('An inconsistent legacy binding passed migration.');
        } catch (RuntimeException $exception) {
            self::assertSame('Invalid legacy Git binding: projects.'.$field.' id='.$project->id, $exception->getMessage());
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    /** @return iterable<string, array{string}> */
    public static function rollbackBlockers(): iterable
    {
        yield 'bound sha1' => ['project'];
        yield 'open sha1 clone intent' => ['open'];
        yield 'failed sha1 clone intent' => ['failed'];
    }

    #[DataProvider('rollbackBlockers')]
    public function test_refused_rollback_is_atomic_including_the_migration_registration(string $kind): void
    {
        if ($kind === 'project') {
            Project::query()->create(['name' => 'SHA1', 'object_format' => 'sha1', 'control_oid' => str_repeat('a', 40)]);
        } else {
            $operation = $this->cloneIntent(40);
            if ($kind === 'failed') {
                ControlOperationResult::query()->create([
                    'control_operation_id' => $operation, 'outcome' => 'failed',
                    'result_binding' => hash('sha256', 'legacy-failure'), 'safe_summary' => 'Legacy clone failure',
                ]);
                DB::table('control_operations')->where('id', $operation)->update([
                    'state' => 'failed', 'phase' => 'attempt_completed', 'completed_at' => now(), 'version' => DB::raw('version + 1'),
                ]);
            }
        }
        $before = $this->snapshot();
        try {
            Artisan::call('migrate:rollback', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true]);
            self::fail('Rollback discarded a SHA-1 binding.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('SHA-1', $exception->getMessage());
        }
        self::assertSame($before, $this->snapshot());
    }

    public function test_open_first_clone_intent_remains_unbound_and_its_serialized_contract_survives_upgrade(): void
    {
        $id = $this->cloneIntent(64);
        $row = (array) DB::table('control_operations')->where('id', $id)->first();
        $this->migrate('down');
        $this->migrate('up');
        self::assertSame($row, (array) DB::table('control_operations')->where('id', $id)->first());
        self::assertNull(Project::query()->findOrFail($row['project_id'])->object_format);
        $parameters = json_decode($row['operation_parameters_jcs'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($parameters, ControlOperationType::MANAGED_CLONE->parameters($parameters));
    }

    public function test_project_binding_rejects_wrong_format_zero_null_downgrade_and_format_change(): void
    {
        foreach (GitObjectFormat::cases() as $format) {
            $project = Project::query()->create(['name' => $format->value]);
            self::assertNull($project->object_format);
            $this->reject(fn () => $project->update(['control_oid' => str_repeat('a', $format->length())]));
            $project->refresh()->update(['object_format' => $format, 'control_oid' => str_repeat('a', $format->length())]);
            foreach (['control_oid', 'pending_control_oid'] as $field) {
                foreach ([str_repeat('a', $format->length() === 40 ? 64 : 40), $format->zeroOid(), str_repeat('A', $format->length())] as $value) {
                    $this->reject(fn () => DB::table('projects')->where('id', $project->id)->update([$field => $value]));
                }
            }
            foreach ([null, 'sha512', $format === GitObjectFormat::SHA1 ? 'sha256' : 'sha1'] as $value) {
                $this->reject(fn () => DB::table('projects')->where('id', $project->id)->update(['object_format' => $value]));
            }
            self::assertSame(1, DB::table('projects')->where('id', $project->id)->update(['control_oid' => null]));
            self::assertSame($format, $project->refresh()->object_format);
        }
    }

    public function test_first_clone_exception_does_not_accept_missing_projects_unbound_fetches_or_bound_foreign_formats(): void
    {
        $intent = $this->cloneIntent(40);
        $row = (array) DB::table('control_operations')->where('id', $intent)->first();
        self::assertTrue(DB::table('control_operations')->insert([...$row, 'id' => (string) Str::uuid()]));
        $bound = Project::query()->create(['name' => 'Bound SHA256', 'object_format' => 'sha256', 'control_oid' => str_repeat('a', 64)]);
        foreach ([
            ['project_id' => PHP_INT_MAX],
            ['project_id' => $bound->id],
            ['operation_type' => 'managed_fetch'],
            ['expected_control_commit' => str_repeat('a', 40)],
            ['target_control_oid' => str_repeat('0', 40)],
            ['target_control_oid' => null],
            ['request_hash' => str_repeat('a', 40)],
        ] as $change) {
            $invalid = [...$row, ...$change, 'id' => (string) Str::uuid()];
            $this->reject(fn () => DB::table('control_operations')->insert($invalid));
        }
        self::assertSame(2, DB::table('control_operations')->count());
    }

    private function cloneIntent(int $length): string
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($actor);
        $project->update(['provisioning_status' => ProjectProvisioningStatus::PROVISIONED, 'deploy_key_reference' => '/managed/key', 'public_deploy_key' => "ssh-ed25519 fixture\n"]);
        $operation = app(QueueManagedCloneOperation::class)->handle($actor, $project->refresh(), ControlOperationType::MANAGED_CLONE, (string) Str::uuid());
        $token = app(ProjectOperationLease::class)->claim($operation, str_repeat('a', 32));
        self::assertIsInt($token);
        DB::table('control_operations')->where('id', $operation->id)->update([
            'phase' => ControlOperationPhase::LAUNCH_INTENT->value, 'effect_attempt_token' => $token,
            'target_control_oid' => str_repeat('b', $length), 'launch_argument_hash' => str_repeat('c', 64), 'version' => DB::raw('version + 1'),
        ]);

        return $operation->id;
    }

    private function migrate(string $direction): void
    {
        $migration = require base_path('database/migrations/'.self::MIGRATION.'.php');
        self::assertInstanceOf(Migration::class, $migration);
        self::assertIsCallable([$migration, $direction]);
        call_user_func([$migration, $direction]);
    }

    /** @return array<string, string> */
    private function guards(): array
    {
        return DB::table('sqlite_master')->where('type', 'trigger')->orderBy('name')->pluck('sql', 'name')->all();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'schema' => DB::table('sqlite_master')->whereIn('type', ['table', 'trigger', 'index'])->orderBy('name')->get()->toJson(),
            'projects' => DB::table('projects')->orderBy('id')->get()->toJson(),
            'operations' => DB::table('control_operations')->orderBy('id')->get()->toJson(),
            'migrations' => DB::table('migrations')->orderBy('id')->get()->toJson(),
        ];
    }

    private function reject(\Closure $write): void
    {
        try {
            $write();
            self::fail('A format-invalid direct write was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
