<?php

namespace Tests\Feature\Shared\Operations;

use App\AI6\Auth\Models\TotpCredential;
use App\AI6\Auth\TotpSecretCipher;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ImplementationImportException;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRetentionState;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunRetentionSweep;
use App\AI6\Shared\Redaction\RedactionKeyring;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class RestoreRetentionTest extends OperationsTestCase
{
    /** TC-05: use Laravel's real previous-keys binding and the real artifact store. */
    public function test_restored_totp_and_tombstones_keep_working_across_both_key_rotations(): void
    {
        [$run, , $user] = $this->observedRun('AI6-049-ROTATE');
        $secret = $this->createConfirmedTotp($user);
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'removed before rotation');
        $this->quiesceRun($run);
        Date::setTestNow('2026-10-12 12:00:00');
        self::assertSame(1, $this->app->make(RunRetentionSweep::class)->sweep()->artifactsPurged);
        $this->createBackup();
        $this->restoreBackup();

        $oldApplicationKey = config('app.key');
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldApplicationKey]]);
        $this->app->forgetInstance('encrypter');
        self::assertSame($secret, $this->app->make(TotpSecretCipher::class)->decrypt(TotpCredential::query()->firstOrFail()->encrypted_secret));
        $oldRedactionKey = $this->app->make(RedactionKeyring::class)->activeKey();
        $this->bindOperationsKeyring(new RedactionKeyring('backup-key-v2', [
            'backup-key-v1' => ['version' => 1, 'key' => $oldRedactionKey],
            'backup-key-v2' => ['version' => 2, 'key' => random_bytes(32)],
        ]));
        $new = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'created after rotation');
        self::assertSame('backup-key-v2', $new->fingerprint_key_id);
        self::assertSame(2, $new->fingerprint_version);
        $tombstone = RunArtifact::query()->findOrFail($artifact->id);
        self::assertSame(RunArtifactRetentionState::DELETED, $tombstone->retention_state);
        self::assertSame('backup-key-v1', $tombstone->fingerprint_key_id);
        self::assertSame(1, $tombstone->fingerprint_version);
        $this->expectException(ImplementationImportException::class);
        $this->expectExceptionMessage('The run artifact content was removed by retention and is not stored again.');
        $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'removed before rotation');
    }

    /** TC-06: expiry after backup, deletion before restore, every read boundary. */
    public function test_restore_sweeps_expired_data_without_resurrecting_any_raw_bytes(): void
    {
        [$run, $project, $user] = $this->observedRun('AI6-049-RETENTION');
        $tombstone = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'already removed payload');
        $this->quiesceRun($run);
        Date::setTestNow('2026-10-12 12:00:00');
        $this->app->make(RunRetentionSweep::class)->sweep();
        $tombstoneRow = RunArtifact::query()->findOrFail($tombstone->id)->toArray();
        $expires = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'expires between backup and restore');
        $valid = $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, 'still valid payload');
        config(['ai6.retention.run_logs.max_days' => 1, 'ai6.retention.check_logs.max_days' => 1]);
        $log = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'expired run log payload', 'restore-log');
        $check = $this->seedObservedCheckResult($run, 'expired check log payload');
        $expiredPath = $this->observedArtifactPath($expires);
        self::assertIsString($expiredPath);
        $this->createBackup();

        Date::setTestNow('2026-10-27 12:00:00');
        self::assertSame(1, $this->app->make(RunRetentionSweep::class)->sweep()->artifactsPurged);
        self::assertFileDoesNotExist($expiredPath);
        $this->restoreBackup();

        self::assertStringContainsString('Artefakte=1', $this->lastOutput);
        self::assertSame(RunArtifactRetentionState::DELETED, RunArtifact::query()->findOrFail($expires->id)->retention_state);
        self::assertFileDoesNotExist($expiredPath);
        self::assertNull($this->app->make(RunArtifactStore::class)->bytes(RunArtifact::query()->findOrFail($expires->id)));
        self::assertSame('still valid payload', $this->app->make(RunArtifactStore::class)->bytes(RunArtifact::query()->findOrFail($valid->id)));
        self::assertSame($tombstoneRow, RunArtifact::query()->findOrFail($tombstone->id)->toArray());
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($log->id)->redacted_payload);
        self::assertSame(RunRetentionSweep::CHECK_LOG_TOMBSTONE, CheckResultRecord::query()->findOrFail($check->id)->redacted_output);
        $this->actingAs($user)->get(route('projects.runs.show', [$project, $run->id]))
            ->assertOk()->assertDontSee('expired run log payload')->assertDontSee('expired check log payload')->assertDontSee('expires between backup and restore');
        foreach ([$expires, $tombstone] as $removed) {
            $this->actingAs($user)->get(route('projects.runs.artifacts.download', [$project, $run->id, $removed->id]))->assertStatus(410);
        }
        $before = $this->liveBytes();
        $again = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(0, $again->total());
        self::assertSame(0, $again->failed);
        self::assertSame(0, $again->deferred);
        self::assertSame($before, $this->liveBytes());
    }

    /** TC-04: fault after switch, inside the real retention file deletion. */
    public function test_a_retention_deletion_failure_makes_restore_incomplete_and_preserves_previous_data(): void
    {
        [$run] = $this->observedRun('AI6-049-DELETE');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, 'due payload');
        $path = $this->observedArtifactPath($artifact);
        self::assertIsString($path);
        $this->quiesceRun($run);
        $this->createBackup();
        Date::setTestNow('2026-10-12 12:00:00');
        $injected = false;
        DB::listen(static function (QueryExecuted $query) use ($path, &$injected): void {
            if (! $injected && str_starts_with($query->sql, 'delete from "login_confirmations"')) {
                $injected = true;
                // A directory at the stored-object path is an OS-independent
                // deletion fault; no production binding or guard is replaced.
                self::assertTrue(unlink($path));
                self::assertTrue(mkdir($path, 0700));
            }
        });

        $this->restoreBackup(1, 'Restore unvollständig: restore_retention_incomplete');

        self::assertTrue($injected);
        self::assertSame(RunArtifactRetentionState::STORED, RunArtifact::query()->findOrFail($artifact->id)->retention_state);
        self::assertNull($this->app->make(RunArtifactStore::class)->bytes(RunArtifact::query()->findOrFail($artifact->id)));
        self::assertCount(1, glob($this->databaseFile.'.pre-restore-*'));
        self::assertCount(1, glob($this->root.'/run-artifacts.pre-restore-*'));
        self::assertCount(1, glob($this->root.'/managed/credentials/deploy-keys.pre-restore-*'));
        self::assertCount(1, glob($this->root.'/managed/credentials/known_hosts.pre-restore-*'));
        self::assertStringContainsString('run-artifacts: neuer Stand', $this->lastOutput);
        self::assertStringContainsString('database.sqlite: neuer Stand', $this->lastOutput);
    }
}
