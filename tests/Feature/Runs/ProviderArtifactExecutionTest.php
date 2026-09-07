<?php

namespace Tests\Feature\Runs;

use App\AI6\Runs\ImplementationImportException;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class ProviderArtifactExecutionTest extends TicketUiTestCase
{
    use BuildsObservedRunFixture;

    public function test_identical_answers_keep_distinct_usage_files_and_retention_without_resurrection(): void
    {
        [$run] = $this->observedRun('AI6-047-ARTIFACTS');
        $store = $this->app->make(RunArtifactStore::class);
        $context = new RedactionContext((string) $run->project_id, $run->id, 'provider-artifact');
        $first = $store->store($run, RunArtifactKind::PROVIDER_RAW, '{}', ['usage' => ['tokens' => 3], 'usage_source' => 'provider'], $context, str_repeat('a', 64));
        $second = $store->store($run, RunArtifactKind::PROVIDER_RAW, '{}', ['usage' => ['tokens' => 8], 'usage_source' => 'provider'], $context, str_repeat('b', 64));
        self::assertNotSame($first->id, $second->id);
        self::assertSame($first->digest, $second->digest);
        self::assertNotSame($first->storage_reference, $second->storage_reference);
        self::assertSame(3, $first->redacted_metadata['usage']['tokens']);
        self::assertSame(8, $second->redacted_metadata['usage']['tokens']);
        self::assertSame($first->id, $store->store($run, RunArtifactKind::PROVIDER_RAW, '{}', $first->redacted_metadata, $context, $first->execution_id)->id);

        self::assertTrue($store->purge($first, Date::now()));
        self::assertSame('{}', $store->bytes($second->fresh()));
        self::assertSame(str_repeat('a', 64), $first->refresh()->execution_id);
        self::assertNull($first->digest);
        foreach ([str_repeat('a', 64), str_repeat('c', 64)] as $execution) {
            try {
                $store->store($run, RunArtifactKind::PROVIDER_RAW, '{}', $first->redacted_metadata, $context, $execution);
                self::fail('Retention must refuse a redelivered answer.');
            } catch (ImplementationImportException $exception) {
                self::assertSame('artifact_retention_expired', $exception->reason);
            }
        }
        self::assertSame(2, RunArtifact::query()->where('run_id', $run->id)->count());
    }

    public function test_an_execution_cannot_be_rebound_to_changed_bytes_or_usage(): void
    {
        [$run] = $this->observedRun('AI6-047-IMMUTABLE');
        $store = $this->app->make(RunArtifactStore::class);
        $context = new RedactionContext((string) $run->project_id, $run->id, 'provider-artifact');
        $id = str_repeat('a', 64);
        $store->store($run, RunArtifactKind::PROVIDER_RAW, '{}', ['usage_source' => 'unknown'], $context, $id);
        foreach ([['changed', ['usage_source' => 'unknown']], ['{}', ['usage_source' => 'provider']]] as [$bytes, $metadata]) {
            try {
                $store->store($run, RunArtifactKind::PROVIDER_RAW, $bytes, $metadata, $context, $id);
                self::fail('The immutable execution artifact was rebound.');
            } catch (ImplementationImportException $exception) {
                self::assertSame('artifact_execution_binding_mismatch', $exception->reason);
            }
        }
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)->count());
    }

    /**
     * Every branch of run_artifacts_execution_insert_guard driven directly:
     * RunArtifactStore::persist() already refuses the same inputs in PHP, so
     * only a raw insert reaches the trigger itself.
     */
    public function test_the_insert_guard_rejects_a_foreign_kind_wrong_length_or_non_hex_execution_id(): void
    {
        [$run] = $this->observedRun('AI6-047-INSERT-GUARD');
        $baseline = $this->app->make(RunArtifactStore::class)->store($run, RunArtifactKind::PROVIDER_RAW, '{}', [],
            new RedactionContext((string) $run->project_id, $run->id, 'provider-artifact'), str_repeat('a', 64));
        $row = (array) DB::table('run_artifacts')->where('id', $baseline->id)->first();

        foreach ([
            'foreign kind' => ['kind' => 'implementation_summary', 'execution_id' => str_repeat('b', 64)],
            'wrong length' => ['kind' => 'provider_raw', 'execution_id' => str_repeat('b', 63)],
            'non hex characters' => ['kind' => 'provider_raw', 'execution_id' => str_repeat('g', 64)],
        ] as $case => $overrides) {
            $candidate = [...$row, ...$overrides, 'id' => (string) Str::uuid()];
            try {
                DB::table('run_artifacts')->insert($candidate);
                self::fail('The insert guard accepted an invalid execution binding: '.$case);
            } catch (QueryException $exception) {
                self::assertStringContainsString('invalid artifact execution binding', $exception->getMessage(), $case);
            }
        }
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)->count());
    }

    public function test_the_database_rejects_execution_mutation_and_metadata_overwrite(): void
    {
        [$run] = $this->observedRun('AI6-047-GUARDS');
        $artifact = $this->app->make(RunArtifactStore::class)->store($run, RunArtifactKind::PROVIDER_RAW, '{}', [],
            new RedactionContext((string) $run->project_id, $run->id, 'provider-artifact'), str_repeat('a', 64));
        foreach ([['execution_id' => null], ['execution_id' => str_repeat('b', 64)], ['redacted_metadata' => '{"usage":2}']] as $values) {
            try {
                DB::table('run_artifacts')->where('id', $artifact->id)->update($values);
                self::fail('The database accepted a mutation of bound evidence.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('artifact', $exception->getMessage());
            }
        }
        self::assertSame(str_repeat('a', 64), $artifact->refresh()->execution_id);
        self::assertSame([], $artifact->redacted_metadata);
    }

    public function test_migration_roundtrip_preserves_legacy_artifacts_and_all_prior_guards(): void
    {
        [$run] = $this->observedRun('AI6-047-MIGRATION');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{}', ['legacy' => true]);
        $guards = DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'not like', 'run_artifacts_execution_%')->orderBy('name')->pluck('sql', 'name')->all();
        $migration = require base_path('database/migrations/2026_09_07_000000_bind_provider_artifacts_to_execution.php');
        self::assertInstanceOf(Migration::class, $migration);
        self::assertIsCallable([$migration, 'down']);
        call_user_func([$migration, 'down']);
        self::assertFalse(Schema::hasColumn('run_artifacts', 'execution_id'));
        self::assertIsCallable([$migration, 'up']);
        call_user_func([$migration, 'up']);
        self::assertTrue(Schema::hasColumn('run_artifacts', 'execution_id'));
        self::assertSame($guards, DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'not like', 'run_artifacts_execution_%')->orderBy('name')->pluck('sql', 'name')->all());
        self::assertSame($artifact->id, $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{}')->id);
        self::assertSame(['legacy' => true, 'kind' => 'provider_raw'], $artifact->refresh()->redacted_metadata);
        self::assertNull($artifact->execution_id);
    }

    public function test_rollback_refuses_to_lose_distinct_turns(): void
    {
        [$run] = $this->observedRun('AI6-047-ROLLBACK');
        $store = $this->app->make(RunArtifactStore::class);
        foreach (['a', 'b'] as $char) {
            $store->store($run, RunArtifactKind::PROVIDER_RAW, '{}', [],
                new RedactionContext((string) $run->project_id, $run->id, 'provider-artifact'), str_repeat($char, 64));
        }
        $migration = require base_path('database/migrations/2026_09_07_000000_bind_provider_artifacts_to_execution.php');
        self::assertInstanceOf(Migration::class, $migration);
        try {
            self::assertIsCallable([$migration, 'down']);
            call_user_func([$migration, 'down']);
            self::fail('Rollback must not discard distinct turns.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The legacy artifact uniqueness cannot represent the stored provider turns.', $exception->getMessage());
        }
        self::assertTrue(Schema::hasColumn('run_artifacts', 'execution_id'));
        self::assertSame(2, RunArtifact::query()->where('run_id', $run->id)->count());
    }
}
