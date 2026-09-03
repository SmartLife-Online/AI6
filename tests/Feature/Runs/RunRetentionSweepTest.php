<?php

namespace Tests\Feature\Runs;

use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResult;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Runs\ExecutionJobState;
use App\AI6\Runs\ImplementationImportException;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRetentionState;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Runs\RunRetentionSweep;
use App\AI6\Runs\RunTransitionConflict;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\After;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * AI6-031 TC-11 and TC-13: the retention run with a controlled clock over run
 * logs, agent raw output, check logs and artifacts; the deterministic lock of
 * every output path afterwards.
 */
final class RunRetentionSweepTest extends TicketUiTestCase
{
    use BuildsObservedRunFixture;

    /** TC-11 */
    public function test_the_retention_run_removes_expired_raw_data_with_its_storage_objects_and_repeats_without_effect(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC11');
        $raw = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"ROHDATEN-PROVIDER-4711"}');
        $summary = $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, '{"changed_files":[],"decisions":[],"marker":"ROHDATEN-SUMMARY-4711"}');
        $check = $this->seedObservedCheckResult($run, 'ROHDATEN-CHECK-4711');
        $event = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'ROHDATEN-LOG-4711', 'ai6-031-tc11-event');
        // Both logs are bound at their persistence: expiry from the trusted
        // value of that moment, size and the keyed fingerprint of the text.
        $boundCheck = CheckResultRecord::query()->findOrFail($check->id);
        $boundEvent = RunEvent::query()->findOrFail($event->id);
        self::assertSame('2026-10-02 12:00:00', $boundCheck->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-01 12:00:00', $boundEvent->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(strlen($check->redacted_output), $boundCheck->retention_size_bytes);
        $boundEventFingerprint = (string) $boundEvent->fingerprint;
        self::assertSame(64, strlen($boundEventFingerprint));
        self::assertTrue(ctype_xdigit($boundEventFingerprint));
        self::assertSame('app-key-v1', $boundEvent->fingerprint_key_id);
        $rawPath = $this->observedArtifactPath($raw);
        $summaryPath = $this->observedArtifactPath($summary);
        self::assertIsString($rawPath);
        self::assertIsString($summaryPath);
        self::assertFileExists($rawPath);
        self::assertFileExists($summaryPath);
        $sweep = $this->app->make(RunRetentionSweep::class);

        // Day 15: the provider output expired (14 days), but the run is still
        // running and may defer within the seven-day grace.
        Date::setTestNow('2026-09-17 12:00:00');
        $deferred = $sweep->sweep();
        self::assertSame(0, $deferred->artifactsPurged);
        self::assertSame(1, $deferred->deferred);
        self::assertFileExists($rawPath);
        self::assertSame(RunArtifactRetentionState::STORED, RunArtifact::query()->findOrFail($raw->id)->retention_state);

        // Day 22: the grace is over; the active run defers no further.
        Date::setTestNow('2026-09-24 12:00:00');
        $purged = $sweep->sweep();
        self::assertSame(1, $purged->artifactsPurged);
        self::assertFileDoesNotExist($rawPath);
        self::assertFileExists($summaryPath, 'The 30-day artifact category is not due yet.');
        $tombstone = RunArtifact::query()->findOrFail($raw->id);
        self::assertSame(RunArtifactRetentionState::DELETED, $tombstone->retention_state);
        self::assertNull($tombstone->digest);
        self::assertNull($tombstone->storage_reference);
        self::assertSame('2026-09-24 12:00:00', $tombstone->deleted_at?->format('Y-m-d H:i:s'));
        self::assertSame($raw->fingerprint, $tombstone->fingerprint);
        self::assertSame('app-key-v1', $tombstone->fingerprint_key_id);
        self::assertSame(1, $tombstone->fingerprint_version);
        self::assertSame($raw->size_bytes, $tombstone->size_bytes);
        self::assertSame(['kind' => 'provider_raw'], $tombstone->redacted_metadata);

        // Day 100: artifacts (30), check logs (30) and run logs (90) are all past
        // expiry and grace; storage objects, outputs and payloads are gone.
        Date::setTestNow('2026-12-11 12:00:00');
        $all = $sweep->sweep();
        self::assertSame(1, $all->artifactsPurged);
        self::assertSame(1, $all->checkLogsPurged);
        self::assertGreaterThanOrEqual(1, $all->runLogsPurged);
        self::assertFileDoesNotExist($summaryPath);
        self::assertDirectoryDoesNotExist(dirname($summaryPath), 'The empty run directory is removed as well.');
        self::assertSame(RunRetentionSweep::CHECK_LOG_TOMBSTONE, CheckResultRecord::query()->findOrFail($check->id)->redacted_output);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload);
        // Both log tombstones are bound like the artifact tombstone: the
        // central project- and run-bound HMAC of the removed redacted text with
        // key id and version, its size, the expiry that applied and the
        // deletion time — on the existing row, in no second table.
        $generator = $this->app->make(RedactionFingerprintGenerator::class);
        $checkTombstone = CheckResultRecord::query()->findOrFail($check->id);
        self::assertSame('2026-12-11 12:00:00', $checkTombstone->retention_deleted_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-02 12:00:00', $checkTombstone->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(strlen($check->redacted_output), $checkTombstone->retention_size_bytes);
        self::assertSame('app-key-v1', $checkTombstone->fingerprint_key_id);
        self::assertSame(1, $checkTombstone->fingerprint_version);
        self::assertSame($generator->generate(RedactionMatchType::SECRET, new RedactionContext((string) $run->project_id, $run->id, 'check-log'), $check->redacted_output)->value, $checkTombstone->fingerprint);
        $eventTombstone = RunEvent::query()->findOrFail($event->id);
        self::assertSame('2026-12-11 12:00:00', $eventTombstone->retention_deleted_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-01 12:00:00', $eventTombstone->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(strlen($event->redacted_payload), $eventTombstone->retention_size_bytes);
        self::assertSame('app-key-v1', $eventTombstone->fingerprint_key_id);
        self::assertSame(1, $eventTombstone->fingerprint_version);
        self::assertSame($generator->generate(RedactionMatchType::SECRET, new RedactionContext((string) $run->project_id, $run->id, 'run-log'), $event->redacted_payload)->value, $eventTombstone->fingerprint);
        self::assertSame($boundEventFingerprint, $eventTombstone->fingerprint, 'The tombstone keeps the binding of the persistence.');
        self::assertSame('ai6-031-tc11-event', $eventTombstone->event_key, 'The event keeps its keyed identity; a redelivered message finds the tombstone instead of writing the payload again.');
        $redelivered = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'ROHDATEN-LOG-4711', 'ai6-031-tc11-event');
        self::assertSame($event->id, $redelivered->id);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload, 'The redelivery resurrects nothing.');
        foreach ([(array) DB::table('run_events')->where('id', $event->id)->first(), (array) DB::table('check_results')->where('id', $check->id)->first()] as $row) {
            $encoded = json_encode($row, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('ROHDATEN', $encoded);
            self::assertStringNotContainsString(hash('sha256', $event->redacted_payload), $encoded);
            self::assertStringNotContainsString(hash('sha256', implode(':', [$run->id, 'implement', 'running', 'ROHDATEN-LOG-4711'])), $encoded, 'No unkeyed digest of the message remains.');
            self::assertStringNotContainsString(hash('sha256', $check->redacted_output), $encoded);
        }
        self::assertFalse(DB::getSchemaBuilder()->hasTable('run_event_tombstones'));
        self::assertFalse(DB::getSchemaBuilder()->hasTable('check_log_tombstones'));
        self::assertSame(0, DB::table('run_events')->where('redacted_payload', 'like', '%ROHDATEN-LOG%')->count());
        self::assertSame(0, DB::table('check_results')->where('redacted_output', 'like', '%ROHDATEN-CHECK%')->count());
        self::assertSame(0, RunArtifact::query()->where('run_id', $run->id)->whereNotNull('storage_reference')->count());
        foreach ($this->filesUnder($this->observedArtifactRoot()) as $file) {
            self::assertStringNotContainsString('ROHDATEN', (string) file_get_contents($file), $file);
        }

        // A second run over the same data is effect-free and raises nothing.
        $rows = DB::table('run_artifacts')->where('run_id', $run->id)->orderBy('sequence')->get()->map(static fn (object $row): array => (array) $row)->all();
        $checkRow = (array) DB::table('check_results')->where('id', $check->id)->first();
        $eventRow = (array) DB::table('run_events')->where('id', $event->id)->first();
        $again = $sweep->sweep();
        self::assertSame(0, $again->total());
        self::assertSame(0, $again->deferred);
        self::assertSame($rows, DB::table('run_artifacts')->where('run_id', $run->id)->orderBy('sequence')->get()->map(static fn (object $row): array => (array) $row)->all());
        self::assertSame($checkRow, (array) DB::table('check_results')->where('id', $check->id)->first());
        self::assertSame($eventRow, (array) DB::table('run_events')->where('id', $event->id)->first());

        // The check result stays immutable apart from the tombstone transition.
        try {
            CheckResultRecord::query()->whereKey($check->id)->update(['state' => 'succeeded']);
            self::fail('The check result must stay immutable.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('check results are immutable', $exception->getMessage());
        }
        // A tombstone never returns to the stored state.
        try {
            RunArtifact::query()->whereKey($raw->id)->update(['retention_state' => 'stored', 'digest' => str_repeat('a', 64), 'storage_reference' => 'x/y']);
            self::fail('A tombstone must not be revived.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('invalid run artifact transition', $exception->getMessage());
        }
        // Neither does a log tombstone: its payload and binding stay for good,
        // and a new event never carries tombstone columns.
        try {
            RunEvent::query()->whereKey($event->id)->update(['redacted_payload' => 'wieder da', 'event_key' => 'x']);
            self::fail('A run-log tombstone must not be revived.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('invalid run event retention transition', $exception->getMessage());
        }
        try {
            RunEvent::query()->create(['run_id' => $run->id, 'event_type' => 'x', 'redacted_payload' => 'y', 'retention_deleted_at' => Date::now()]);
            self::fail('A new event must not carry a deletion.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('invalid run event', $exception->getMessage());
        }
        try {
            DB::table('run_events')->insert(['run_id' => $run->id, 'event_type' => 'x', 'redacted_payload' => 'y', 'created_at' => Date::now(), 'updated_at' => Date::now()]);
            self::fail('A new event must carry its retention binding.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('invalid run event', $exception->getMessage());
        }
        self::assertSame(0, DB::table('jobs')->count());
        $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]))->assertOk();
    }

    /** TC-11: a terminal run defers nothing; expiry alone decides. */
    public function test_a_terminal_run_does_not_defer_and_the_grace_is_bounded_for_an_active_run(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-TC11-T');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"terminal"}');
        $path = $this->observedArtifactPath($artifact);
        self::assertIsString($path);
        $sweep = $this->app->make(RunRetentionSweep::class);

        // Active run, one second before the grace ends: still deferred.
        Date::setTestNow('2026-09-23 11:59:59');
        self::assertSame(1, $sweep->sweep()->deferred);
        self::assertFileExists($path);

        // The run ends; the same moment now purges without any grace.
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        self::assertSame(1, $sweep->sweep()->artifactsPurged);
        self::assertFileDoesNotExist($path);
    }

    /** TC-13 */
    public function test_after_expiry_neither_view_download_nor_the_primary_store_hands_out_the_removed_bytes_and_size_limits_still_bind(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-TC13');
        $bytes = '{"raw":"ROHDATEN-GELOESCHT-9001"}';
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, $bytes);
        $path = $this->observedArtifactPath($artifact);
        self::assertIsString($path);
        $download = route('projects.runs.artifacts.download', [$project, $run->id, $artifact->id]);
        $this->actingAs($operator)->get($download)->assertOk();

        Date::setTestNow('2026-09-24 12:00:00');
        self::assertSame(1, $this->app->make(RunRetentionSweep::class)->sweep()->artifactsPurged);
        $store = $this->app->make(RunArtifactStore::class);
        $tombstone = RunArtifact::query()->findOrFail($artifact->id);

        // View: the tombstone, never the bytes.
        $view = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $view->assertOk();
        $view->assertSee('data-artifact-retention="deleted"', false);
        $view->assertDontSee('ROHDATEN-GELOESCHT-9001');
        $view->assertDontSee('data-artifact-download="'.$artifact->id.'"', false);

        // Download: refused deterministically.
        $refused = $this->actingAs($operator)->get($download);
        $refused->assertStatus(410);
        self::assertStringNotContainsString('ROHDATEN', (string) $refused->getContent());

        // Primary store: no bytes, no storage object, and a redelivered copy of
        // the removed content — what a retried queue job would store — is refused by name.
        self::assertNull($store->bytes($tombstone));
        self::assertFileDoesNotExist($path);
        try {
            $store->store($run->fresh() ?? $run, RunArtifactKind::PROVIDER_RAW, $bytes, ['kind' => 'provider_raw'], new RedactionContext((string) $run->project_id, $run->id, 'run-observation-test'));
            self::fail('Removed content must not be stored again.');
        } catch (ImplementationImportException $exception) {
            self::assertSame('artifact_retention_expired', $exception->reason);
        }
        self::assertFileDoesNotExist($path);
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)->where('kind', RunArtifactKind::PROVIDER_RAW->value)->count());

        // The active run stays bound by the configured size limits.
        config(['ai6.retention.agent_raw_output.max_bytes' => 8]);
        $this->app->forgetInstance(RunArtifactStore::class);
        try {
            $this->app->make(RunArtifactStore::class)->store($run->fresh() ?? $run, RunArtifactKind::PROVIDER_RAW, '{"raw":"zu gross"}', ['kind' => 'provider_raw'], new RedactionContext((string) $run->project_id, $run->id, 'run-observation-test'));
            self::fail('The size limit must bind.');
        } catch (ImplementationImportException $exception) {
            self::assertSame('artifact_retention_size_exceeded', $exception->reason);
        }
        self::assertSame([], $this->filesUnder($this->observedArtifactRoot()));
    }

    /** AC-09: retention claims carried by provider output or artifact metadata change nothing. */
    public function test_provider_supplied_retention_values_do_not_set_or_extend_the_expiry(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-CLAIM');
        $claim = '{"retention":{"agent_raw_output":{"max_days":3650,"max_bytes":1},"artifacts":{"max_days":3650}},"summary":"AI6_RETENTION_ARTIFACTS_MAX_DAYS=3650"}';

        $raw = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, $claim, ['retention' => ['max_days' => 3650], 'expires_at' => '2036-09-02 12:00:00']);
        $summary = $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, $claim, ['retention' => ['max_days' => 3650]]);

        self::assertSame('2026-09-16 12:00:00', $raw->expires_at->format('Y-m-d H:i:s'), 'agent_raw_output: 14 days from trusted config.');
        self::assertSame('2026-10-02 12:00:00', $summary->expires_at->format('Y-m-d H:i:s'), 'artifacts: 30 days from trusted config.');
    }

    /** AC-11: one storage object that cannot be removed neither halts the other categories nor the next run. */
    public function test_one_undeletable_storage_object_does_not_halt_the_sweep_and_is_retried(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-STUCK-A');
        [$otherRun] = $this->secondObservedRun('AI6-031-STUCK-B');
        $stuck = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"blockiert"}');
        $free = $this->storeObservedArtifact($otherRun, RunArtifactKind::PROVIDER_RAW, '{"raw":"frei"}');
        $check = $this->seedObservedCheckResult($run, 'Ausgabe');
        $event = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'Logzeile', 'ai6-031-stuck-event');
        $stuckPath = $this->observedArtifactPath($stuck);
        $freePath = $this->observedArtifactPath($free);
        self::assertIsString($stuckPath);
        self::assertIsString($freePath);
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        $this->app->make(RunOrchestrator::class)->failRun($otherRun->id);
        Date::setTestNow('2026-12-15 12:00:00');

        $windows = DIRECTORY_SEPARATOR === '\\';
        if ($windows) {
            // PHP on Windows unlinks even open files, so the object at the
            // path is replaced by a directory the deletion cannot claim.
            self::assertTrue(unlink($stuckPath));
            self::assertTrue(mkdir($stuckPath, 0700));
            self::assertNotFalse(file_put_contents($stuckPath.DIRECTORY_SEPARATOR.'blocker', 'x'));
        } else {
            // POSIX refuses to unlink inside a directory without write permission.
            self::assertTrue(chmod(dirname($stuckPath), 0500));
        }
        try {
            $result = $this->app->make(RunRetentionSweep::class)->sweep();
        } finally {
            if ($windows) {
                unlink($stuckPath.DIRECTORY_SEPARATOR.'blocker');
                rmdir($stuckPath);
            } else {
                chmod(dirname($stuckPath), 0700);
            }
        }

        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->artifactsPurged, 'The other run\'s artifact is purged in the same run.');
        self::assertSame(1, $result->checkLogsPurged, 'The check logs are purged although an artifact failed.');
        self::assertGreaterThanOrEqual(1, $result->runLogsPurged);
        if (! $windows) {
            self::assertFileExists($stuckPath);
        }
        self::assertFileDoesNotExist($freePath);
        self::assertSame(RunArtifactRetentionState::STORED, RunArtifact::query()->findOrFail($stuck->id)->retention_state, 'No tombstone without a removed storage object.');
        self::assertSame(RunRetentionSweep::CHECK_LOG_TOMBSTONE, CheckResultRecord::query()->findOrFail($check->id)->redacted_output);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload);

        // The next run, with the object removable again, finishes the deletion.
        $retry = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(0, $retry->failed);
        self::assertSame(1, $retry->artifactsPurged);
        self::assertFileDoesNotExist($stuckPath);
        self::assertSame(RunArtifactRetentionState::DELETED, RunArtifact::query()->findOrFail($stuck->id)->retention_state);
    }

    /** AC-11: without the trusted root in reach no tombstone is written. */
    public function test_an_unreachable_artifact_root_refuses_the_deletion_instead_of_writing_a_tombstone(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-NOROOT');
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, '{"raw":"unerreichbar"}');
        $path = $this->observedArtifactPath($artifact);
        self::assertIsString($path);
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-12-15 12:00:00');

        config(['ai6.run_artifacts.root' => $this->observedArtifactRoot().DIRECTORY_SEPARATOR.'not-mounted']);
        $this->app->forgetInstance(RunArtifactRoot::class);
        $this->app->forgetInstance(RunArtifactStore::class);
        $result = $this->app->make(RunRetentionSweep::class)->sweep();

        self::assertSame(1, $result->failed);
        self::assertSame(0, $result->artifactsPurged);
        self::assertFileExists($path, 'The bytes under the real root are untouched.');
        self::assertSame(RunArtifactRetentionState::STORED, RunArtifact::query()->findOrFail($artifact->id)->retention_state);
    }

    /** AC-09/AC-13: the trusted run-log size limit binds at persistence, after the redaction. */
    public function test_the_run_log_size_limit_binds_at_persistence_after_the_redaction(): void
    {
        [$run] = $this->observedRun('AI6-031-LOGSIZE');
        config(['ai6.retention.run_logs.max_bytes' => 96]);
        $message = 'Sitzung mit password=hunter2 gestartet: '.str_repeat('ü', 200);

        $event = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message, 'ai6-031-logsize');

        $payload = RunEvent::query()->findOrFail($event->id)->redacted_payload;
        self::assertLessThanOrEqual(96, strlen($payload));
        self::assertStringEndsWith(RunOrchestrator::RUN_LOG_TRUNCATION_MARKER, $payload);
        self::assertStringStartsWith('Sitzung mit password='.RedactionMatchType::SECRET->marker().' gestartet:', $payload);
        self::assertSame(1, preg_match('//u', $payload), 'The cut never splits a multibyte character.');
        self::assertStringNotContainsString('hunter2', $payload);
    }

    /** AC-13: the resurrection lock survives a rotation of the active redaction key; an unverifiable lock refuses by name. */
    public function test_the_resurrection_lock_recognizes_a_tombstone_written_under_a_retired_key_and_refuses_an_unverifiable_one(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-ROTATE');
        $bytes = '{"raw":"ROHDATEN-ROTIERT-77"}';
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, $bytes);
        self::assertSame('app-key-v1', $artifact->fingerprint_key_id);
        $download = route('projects.runs.artifacts.download', [$project, $run->id, $artifact->id]);
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-09-17 12:00:00');
        self::assertSame(1, $this->app->make(RunRetentionSweep::class)->sweep()->artifactsPurged);
        $context = new RedactionContext((string) $run->project_id, $run->id, 'run-observation-test');

        // Rotation: key-v2 becomes active, app-key-v1 stays in the ring retired.
        $retired = $this->app->make(RedactionKeyring::class)->activeKey();
        $this->bindKeyring(new RedactionKeyring('key-v2', [
            'app-key-v1' => ['version' => 1, 'key' => $retired],
            'key-v2' => ['version' => 2, 'key' => str_repeat("\x07", 32)],
        ]));
        try {
            $this->app->make(RunArtifactStore::class)->store($run->fresh() ?? $run, RunArtifactKind::PROVIDER_RAW, $bytes, ['kind' => 'provider_raw'], $context);
            self::fail('The removed bytes must stay locked after the rotation.');
        } catch (ImplementationImportException $exception) {
            self::assertSame('artifact_retention_expired', $exception->reason);
        }
        $this->actingAs($operator)->get($download)->assertStatus(410);
        $fresh = $this->app->make(RunArtifactStore::class)->store($run->fresh() ?? $run, RunArtifactKind::PROVIDER_RAW, '{"raw":"neu nach der Rotation"}', ['kind' => 'provider_raw'], $context);
        self::assertSame('key-v2', $fresh->fingerprint_key_id, 'New artifacts bind to the active key.');
        self::assertSame(2, $fresh->fingerprint_version);
        self::assertSame(RunArtifactRetentionState::DELETED, RunArtifact::query()->findOrFail($artifact->id)->retention_state);

        // A ring without the retired key, or one that binds the retired key id
        // to another version, cannot verify the lock: the store refuses by its
        // own name instead of forging the comparison input.
        foreach ([
            new RedactionKeyring('key-v2', ['key-v2' => ['version' => 2, 'key' => str_repeat("\x07", 32)]]),
            new RedactionKeyring('key-v2', ['app-key-v1' => ['version' => 3, 'key' => $retired], 'key-v2' => ['version' => 2, 'key' => str_repeat("\x07", 32)]]),
        ] as $ring) {
            $this->bindKeyring($ring);
            foreach ([$bytes, '{"raw":"andere Bytes"}'] as $candidate) {
                try {
                    $this->app->make(RunArtifactStore::class)->store($run->fresh() ?? $run, RunArtifactKind::PROVIDER_RAW, $candidate, ['kind' => 'provider_raw'], $context);
                    self::fail('An unverifiable lock must refuse.');
                } catch (ImplementationImportException $exception) {
                    self::assertSame('artifact_retention_lock_unverifiable', $exception->reason);
                }
            }
            $this->actingAs($operator)->get($download)->assertStatus(410);
        }
        self::assertSame(2, RunArtifact::query()->where('run_id', $run->id)->where('kind', RunArtifactKind::PROVIDER_RAW->value)->count());
    }

    /** AC-12/AC-13: a legacy artifact binds its fingerprint before its bytes go, so a purge resumed after a crash between file removal and tombstone update still recognizes a redelivered copy. */
    public function test_a_legacy_artifact_binds_its_fingerprint_before_the_removal_and_a_resumed_purge_keeps_the_lock(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-LEGACY');
        $bytes = '{"raw":"ROHDATEN-ALTBESTAND-5"}';
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, $bytes);
        $expected = (string) $artifact->fingerprint;
        $path = $this->observedArtifactPath($artifact);
        self::assertIsString($path);
        // A row stored before the fingerprint binding: the guards refuse such a
        // row today, so exactly this seeding suspends the update guard.
        $this->withoutTrigger('run_artifacts_update_guard', static fn () => DB::table('run_artifacts')->where('id', $artifact->id)
            ->update(['fingerprint' => null, 'fingerprint_key_id' => null, 'fingerprint_version' => null]));
        self::assertNull(RunArtifact::query()->findOrFail($artifact->id)->fingerprint);
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-09-17 12:00:00');
        $store = $this->app->make(RunArtifactStore::class);

        // The storage object refuses its removal: the binding must already be persisted by then.
        $windows = DIRECTORY_SEPARATOR === '\\';
        self::assertTrue($windows ? chmod($path, 0444) : chmod(dirname($path), 0500));
        try {
            $store->purge(RunArtifact::query()->findOrFail($artifact->id), Date::now());
            self::fail('The removal must fail under the fault.');
        } catch (ImplementationImportException $exception) {
            self::assertSame('artifact_delete_failed', $exception->reason);
        } finally {
            self::assertTrue($windows ? chmod($path, 0600) : chmod(dirname($path), 0700));
        }
        $bound = RunArtifact::query()->findOrFail($artifact->id);
        self::assertSame(RunArtifactRetentionState::STORED, $bound->retention_state);
        self::assertSame($expected, $bound->fingerprint, 'The binding is the central HMAC and is persisted before the bytes go.');
        self::assertSame('app-key-v1', $bound->fingerprint_key_id);
        self::assertSame(1, $bound->fingerprint_version);
        self::assertFileExists($path);

        // The crash after the removal: the object is gone, the row still stored.
        self::assertTrue(unlink($path));
        self::assertTrue($store->purge($bound, Date::now()));
        $tombstone = RunArtifact::query()->findOrFail($artifact->id);
        self::assertSame(RunArtifactRetentionState::DELETED, $tombstone->retention_state);
        self::assertSame($expected, $tombstone->fingerprint);
        self::assertNull($tombstone->storage_reference);
        try {
            $store->store($run->fresh() ?? $run, RunArtifactKind::PROVIDER_RAW, $bytes, ['kind' => 'provider_raw'], new RedactionContext((string) $run->project_id, $run->id, 'run-observation-test'));
            self::fail('The resumed tombstone must still recognize the removed bytes.');
        } catch (ImplementationImportException $exception) {
            self::assertSame('artifact_retention_expired', $exception->reason);
        }
    }

    /** AC-12/AC-13: a legacy artifact whose storage object is already gone becomes a tombstone without binding — and that tombstone locks every further store of its kind by name. */
    public function test_a_legacy_artifact_without_storage_object_becomes_an_unverifiable_tombstone_that_refuses_every_further_store(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-LEGACY-GONE');
        $bytes = '{"raw":"ROHDATEN-ALTBESTAND-OHNE-OBJEKT"}';
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, $bytes);
        $path = $this->observedArtifactPath($artifact);
        self::assertIsString($path);
        $this->withoutTrigger('run_artifacts_update_guard', static fn () => DB::table('run_artifacts')->where('id', $artifact->id)
            ->update(['fingerprint' => null, 'fingerprint_key_id' => null, 'fingerprint_version' => null]));
        self::assertTrue(unlink($path));
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-09-17 12:00:00');

        self::assertSame(1, $this->app->make(RunRetentionSweep::class)->sweep()->artifactsPurged);
        $tombstone = RunArtifact::query()->findOrFail($artifact->id);
        self::assertSame(RunArtifactRetentionState::DELETED, $tombstone->retention_state);
        self::assertNull($tombstone->fingerprint, 'Nothing was left to bind.');
        $context = new RedactionContext((string) $run->project_id, $run->id, 'run-observation-test');
        foreach ([$bytes, '{"raw":"andere Bytes"}'] as $candidate) {
            try {
                $this->app->make(RunArtifactStore::class)->store($run->fresh() ?? $run, RunArtifactKind::PROVIDER_RAW, $candidate, ['kind' => 'provider_raw'], $context);
                self::fail('An unverifiable tombstone must lock the kind.');
            } catch (ImplementationImportException $exception) {
                self::assertSame('artifact_retention_lock_unverifiable', $exception->reason);
            }
        }
        // Another kind of the same run stays storable; the download of the tombstone stays refused.
        $this->storeObservedArtifact($run, RunArtifactKind::IMPLEMENTATION_SUMMARY, '{"changed_files":[],"decisions":[]}');
        $this->actingAs($operator)->get(route('projects.runs.artifacts.download', [$project, $run->id, $artifact->id]))->assertStatus(410);
        self::assertSame(1, RunArtifact::query()->where('run_id', $run->id)->where('kind', RunArtifactKind::PROVIDER_RAW->value)->count());
    }

    /** AC-11: an output that regularly printed the tombstone marker is deleted like any other and blocks neither later logs nor a repeated run. */
    public function test_a_check_output_that_printed_the_marker_is_tombstoned_by_state_and_a_repeated_sweep_stays_effect_free(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-PRINTED');
        $printed = $this->seedObservedCheckResult($run, RunRetentionSweep::CHECK_LOG_TOMBSTONE, 'php-all');
        $later = $this->seedObservedCheckResult($run, 'Reguläre Ausgabe', 'php-targeted');
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-12-15 12:00:00');

        $first = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(2, $first->checkLogsPurged);
        foreach ([$printed, $later] as $result) {
            $tombstone = CheckResultRecord::query()->findOrFail($result->id);
            self::assertNotNull($tombstone->retention_deleted_at);
            self::assertNotNull($tombstone->fingerprint);
            self::assertSame(RunRetentionSweep::CHECK_LOG_TOMBSTONE, $tombstone->redacted_output);
        }
        $rows = DB::table('check_results')->where('run_id', $run->id)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $again = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(0, $again->total());
        self::assertSame($rows, DB::table('check_results')->where('run_id', $run->id)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all());
    }

    /** AC-13: a derived event identity is bound to its key; after a rotation the same message finds its tombstone under the retired key and writes no payload again. */
    public function test_a_repeated_event_after_sweep_and_key_rotation_finds_its_tombstone_instead_of_storing_the_payload_again(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-EVROTATE');
        $message = 'Sitzung mit ROHDATEN-EREIGNIS-31 gestartet.';
        $event = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);
        self::assertSame($event->id, $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message)->id, 'Same key, same event.');
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-12-15 12:00:00');
        self::assertGreaterThanOrEqual(1, $this->app->make(RunRetentionSweep::class)->sweep()->runLogsPurged);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload);
        $eventsBefore = RunEvent::query()->where('run_id', $run->id)->count();

        $retired = $this->app->make(RedactionKeyring::class)->activeKey();
        $this->bindKeyring(new RedactionKeyring('key-v2', [
            'app-key-v1' => ['version' => 1, 'key' => $retired],
            'key-v2' => ['version' => 2, 'key' => str_repeat("\x07", 32)],
        ]));
        $repeated = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);

        self::assertSame($event->id, $repeated->id, 'The identity written under the retired key is recognized.');
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload);
        self::assertSame($eventsBefore, RunEvent::query()->where('run_id', $run->id)->count());
        self::assertSame(0, DB::table('run_events')->where('run_id', $run->id)->where('redacted_payload', 'like', '%ROHDATEN-EREIGNIS%')->count());
        // A new message binds its identity to the active key — never to the
        // retired one, or the rotation would sign nothing new — and stays idempotent.
        $fresh = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'Neue Meldung nach der Rotation.');
        self::assertStringStartsWith(RunOrchestrator::EVENT_IDENTITY_PREFIX.'key-v2:2:', (string) $fresh->event_key);
        self::assertSame($eventsBefore + 1, RunEvent::query()->where('run_id', $run->id)->count());
        self::assertSame($fresh->id, $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'Neue Meldung nach der Rotation.')->id);
    }

    /** SEC-011: a legacy unkeyed message digest is rebound at the tombstone; explicit keys stay; an identity the ring cannot recompute refuses the repetition by name. */
    public function test_legacy_unkeyed_event_digests_are_rebound_at_the_tombstone_and_an_unverifiable_identity_refuses_by_name(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-LEGACYKEY');
        $message = 'Altbestand mit ROHDATEN-ALT-99.';
        $legacyDigest = hash('sha256', implode(':', [$run->id, 'implement', 'running', $message]));
        $event = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);
        self::assertStringStartsWith(RunOrchestrator::EVENT_IDENTITY_PREFIX.'app-key-v1:1:', (string) $event->event_key);
        // The row as the previous contract wrote it: the unkeyed digest as key.
        DB::table('run_events')->where('id', $event->id)->update(['event_key' => $legacyDigest]);
        $skip = RunEvent::query()->create(['run_id' => $run->id, 'event_type' => 'security.review.skipped', 'event_key' => 'security-review-skipped:'.str_repeat('c', 64).':'.str_repeat('d', 64), 'redacted_payload' => 'Security-Review policybedingt übersprungen.']);
        self::assertSame($event->id, $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message)->id, 'The legacy digest is recognized before the sweep.');
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-12-15 12:00:00');

        self::assertGreaterThanOrEqual(2, $this->app->make(RunRetentionSweep::class)->sweep()->runLogsPurged);
        $tombstone = RunEvent::query()->findOrFail($event->id);
        self::assertStringStartsWith(RunOrchestrator::EVENT_IDENTITY_PREFIX.'app-key-v1:1:', (string) $tombstone->event_key);
        self::assertSame(0, DB::table('run_events')->where('run_id', $run->id)->where('event_key', $legacyDigest)->count(), 'No unkeyed message hash remains.');
        self::assertSame($skip->event_key, RunEvent::query()->findOrFail($skip->id)->event_key, 'An explicit business key is untouched.');
        self::assertSame($event->id, $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message)->id, 'The rebound identity still recognizes the message.');
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload);

        // A ring that binds the key id to another version cannot recompute the
        // identities of this run: the repetition check is unverifiable, so the
        // recording refuses by name instead of storing the text again.
        $retired = $this->app->make(RedactionKeyring::class)->activeKey();
        $this->bindKeyring(new RedactionKeyring('key-v2', ['app-key-v1' => ['version' => 5, 'key' => $retired], 'key-v2' => ['version' => 2, 'key' => str_repeat("\x07", 32)]]));
        $eventsBefore = RunEvent::query()->where('run_id', $run->id)->count();
        try {
            $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);
            self::fail('An unverifiable identity must refuse.');
        } catch (RunTransitionConflict $exception) {
            self::assertSame('event_identity_unverifiable', $exception->reason);
        }
        // A ring without the key at all refuses the same way.
        $this->bindKeyring(new RedactionKeyring('key-v2', ['key-v2' => ['version' => 2, 'key' => str_repeat("\x07", 32)]]));
        try {
            $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'Ganz neue Meldung.');
            self::fail('An unverifiable identity must refuse.');
        } catch (RunTransitionConflict $exception) {
            self::assertSame('event_identity_unverifiable', $exception->reason);
        }
        self::assertSame($eventsBefore, RunEvent::query()->where('run_id', $run->id)->count());
        self::assertSame(0, DB::table('run_events')->where('run_id', $run->id)->where('redacted_payload', 'like', '%ROHDATEN-ALT%')->count());
    }

    /** AC-13: the retention run rebinds a legacy digest between the identity lookup and the write; the found event is returned, no second row carries the deleted text. */
    public function test_a_legacy_rebind_between_identity_lookup_and_write_creates_no_second_row_with_the_deleted_payload(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-EVRACE');
        $message = 'Wettlauf mit ROHDATEN-RACE-7.';
        $event = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);
        DB::table('run_events')->where('id', $event->id)->update(['event_key' => hash('sha256', implode(':', [$run->id, 'implement', 'running', $message]))]);
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        Date::setTestNow('2026-12-15 12:00:00');
        $rowsBefore = RunEvent::query()->where('run_id', $run->id)->count();

        // The deterministic interleaving: the retention run commits its rebind
        // right after the identity lookup has read the legacy digest.
        $swept = false;
        DB::listen(function ($query) use (&$swept): void {
            if (! $swept && str_contains($query->sql, 'from "run_events"') && str_contains($query->sql, '"event_key" in (')) {
                $swept = true;
                self::assertGreaterThanOrEqual(1, $this->app->make(RunRetentionSweep::class)->sweep()->runLogsPurged);
            }
        });
        $repeated = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);

        self::assertTrue($swept, 'The interleaving actually happened.');
        self::assertSame($event->id, $repeated->id);
        self::assertSame($rowsBefore, RunEvent::query()->where('run_id', $run->id)->count(), 'No second row was created.');
        self::assertSame(0, DB::table('run_events')->where('run_id', $run->id)->where('redacted_payload', 'like', '%ROHDATEN-RACE%')->count());
        self::assertStringStartsWith(RunOrchestrator::EVENT_IDENTITY_PREFIX, (string) RunEvent::query()->findOrFail($event->id)->event_key);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload);
    }

    /** AC-10/AC-13: a raised retention value after the expiry neither reveals the expired raw data again nor postpones its deletion — not for a bound row, not for a migrated legacy row, and a row without any persisted expiry is closed for good instead of derived from the current value. */
    public function test_a_raised_retention_value_before_the_sweep_neither_revives_expired_logs_nor_postpones_their_deletion(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run, $project, $operator] = $this->observedRun('AI6-031-RAISE-PRE');
        $check = $this->seedObservedCheckResult($run, 'ROHDATEN-VORHER-1');
        $event = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'ROHDATEN-VORHER-2', 'ai6-031-raise-pre');
        // A row stored before the binding existed, exactly as the retention
        // migration leaves it: expiry and size bound once, no fingerprint yet.
        $legacy = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'ROHDATEN-ALT-3', 'ai6-031-raise-legacy');
        $this->withoutTrigger('run_events_retention_update_guard', static fn () => DB::table('run_events')->where('id', $legacy->id)
            ->update(['fingerprint' => null, 'fingerprint_key_id' => null, 'fingerprint_version' => null]));
        // A row without any persisted expiry cannot pass the guards; one that
        // exists nevertheless has no trusted due date at all.
        $unbound = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'ROHDATEN-UNGEBUNDEN-4', 'ai6-031-raise-unbound');
        $this->withoutTrigger('run_events_retention_update_guard', static fn () => DB::table('run_events')->where('id', $unbound->id)
            ->update(['retention_expires_at' => null, 'retention_size_bytes' => null, 'fingerprint' => null, 'fingerprint_key_id' => null, 'fingerprint_version' => null]));
        $this->app->make(RunOrchestrator::class)->failRun($run->id);

        // Day 100: everything is due. The operator raises both values before the sweep runs.
        Date::setTestNow('2026-12-11 12:00:00');
        config(['ai6.retention.run_logs.max_days' => 3650, 'ai6.retention.check_logs.max_days' => 3650]);
        $view = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $view->assertOk();
        $view->assertSee('data-retention-expired="check_logs"', false);
        $view->assertSee('data-retention-expired="run_logs"', false);
        $view->assertSee('data-retention-unbound="run_logs"', false);
        $view->assertSee('ist für diesen Datensatz nicht gebunden; Rohdaten des Eintrags werden nicht ausgegeben');
        $view->assertDontSee('ROHDATEN-VORHER');
        $view->assertDontSee('ROHDATEN-ALT');
        $view->assertDontSee('ROHDATEN-UNGEBUNDEN');
        $view->assertDontSee('data-check-output', false);

        $swept = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(1, $swept->checkLogsPurged);
        self::assertGreaterThanOrEqual(3, $swept->runLogsPurged, 'The bound rows and the migrated legacy row are deleted on their persisted expiry.');
        self::assertSame(1, $swept->failed, 'The unbound row is named and counted, never derived or deleted.');
        self::assertSame(RunRetentionSweep::CHECK_LOG_TOMBSTONE, CheckResultRecord::query()->findOrFail($check->id)->redacted_output);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload);
        self::assertSame('2026-12-01 12:00:00', RunEvent::query()->findOrFail($event->id)->retention_expires_at?->format('Y-m-d H:i:s'), 'The tombstone carries the expiry that applied at persistence.');
        // The migrated legacy row is deleted on its persisted expiry despite
        // the raised value and receives its fingerprint with the tombstone.
        $legacyRow = RunEvent::query()->findOrFail($legacy->id);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, $legacyRow->redacted_payload);
        self::assertSame('2026-12-01 12:00:00', $legacyRow->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('app-key-v1', $legacyRow->fingerprint_key_id);
        self::assertSame(1, $legacyRow->fingerprint_version);
        self::assertSame(strlen('ROHDATEN-ALT-3'), $legacyRow->retention_size_bytes);
        self::assertSame($this->app->make(RedactionFingerprintGenerator::class)->generate(RedactionMatchType::SECRET, new RedactionContext((string) $run->project_id, $run->id, 'run-log'), 'ROHDATEN-ALT-3')->value, $legacyRow->fingerprint);
        // The unbound row stays untouched and closed: no derived expiry, no deletion, no output.
        $unboundRow = RunEvent::query()->findOrFail($unbound->id);
        self::assertNull($unboundRow->retention_deleted_at);
        self::assertNull($unboundRow->retention_expires_at);
        $view = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $view->assertDontSee('ROHDATEN-VORHER');
        $view->assertDontSee('ROHDATEN-ALT');
        $view->assertDontSee('ROHDATEN-UNGEBUNDEN');
        $view->assertSee('data-retention-deleted="run_logs"', false);
        $view->assertSee('data-retention-unbound="run_logs"', false);

        // Restoring the original value changes nothing either: a second run is effect-free and names the unbound row again.
        config(['ai6.retention.run_logs.max_days' => 90]);
        $again = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(0, $again->runLogsPurged);
        self::assertSame(1, $again->failed);
        self::assertNull(RunEvent::query()->findOrFail($unbound->id)->retention_deleted_at);
    }

    /** AC-09/AC-12: a caller-supplied expiry, size, fingerprint or creation time never survives the persistence; every new row carries the server's binding. */
    public function test_a_caller_supplied_retention_binding_is_replaced_by_the_server_binding(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-FORGED');
        $generator = $this->app->make(RedactionFingerprintGenerator::class);
        $forged = [
            'created_at' => '2020-01-01 00:00:00',
            'retention_expires_at' => '2099-12-31 00:00:00',
            'retention_size_bytes' => 1,
            'fingerprint' => str_repeat('f', 64),
            'fingerprint_key_id' => 'forged-key',
            'fingerprint_version' => 99,
        ];

        $event = RunEvent::query()->create(['run_id' => $run->id, 'event_type' => 'forged', 'event_key' => 'ai6-031-forged-event', 'redacted_payload' => 'ROHDATEN-GEFAELSCHT-1', ...$forged]);
        $storedEvent = RunEvent::query()->findOrFail($event->id);
        self::assertSame('2026-09-02 12:00:00', $storedEvent->created_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-12-01 12:00:00', $storedEvent->retention_expires_at?->format('Y-m-d H:i:s'), 'The expiry follows the trusted value from the server persistence time.');
        self::assertSame(strlen('ROHDATEN-GEFAELSCHT-1'), $storedEvent->retention_size_bytes);
        self::assertSame('app-key-v1', $storedEvent->fingerprint_key_id);
        self::assertSame(1, $storedEvent->fingerprint_version);
        self::assertSame($generator->generate(RedactionMatchType::SECRET, new RedactionContext((string) $run->project_id, $run->id, 'run-log'), 'ROHDATEN-GEFAELSCHT-1')->value, $storedEvent->fingerprint);

        $tree = (string) $run->checkpoint_tree_sha;
        $check = CheckResultRecord::query()->create([
            'id' => (string) Str::uuid(), 'run_id' => $run->id, 'phase' => CheckPhase::BEFORE_REVIEW,
            'evidence_epoch' => $run->evidence_epoch, 'profile' => 'forged-profile',
            'state' => CheckResultState::FAILED, 'reason' => 'check_failed', 'exit_code' => 1,
            'duration_ms' => 12, 'redacted_output' => 'ROHDATEN-GEFAELSCHT-2', 'tree_sha' => $tree, 'result_tree_sha' => $tree,
            'declared_side_effects' => false, 'declared_network' => false, 'declared_mutates' => false,
            'result_key' => CheckResult::key($run->id, $run->evidence_epoch, CheckPhase::BEFORE_REVIEW, 'forged-profile', $tree),
            ...$forged,
        ]);
        $storedCheck = CheckResultRecord::query()->findOrFail($check->id);
        self::assertSame('2026-09-02 12:00:00', $storedCheck->created_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-02 12:00:00', $storedCheck->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(strlen('ROHDATEN-GEFAELSCHT-2'), $storedCheck->retention_size_bytes);
        self::assertSame('app-key-v1', $storedCheck->fingerprint_key_id);
        self::assertSame(1, $storedCheck->fingerprint_version);
        self::assertSame($generator->generate(RedactionMatchType::SECRET, new RedactionContext((string) $run->project_id, $run->id, 'check-log'), 'ROHDATEN-GEFAELSCHT-2')->value, $storedCheck->fingerprint);

        // Day 100: the forged far-away expiry postponed nothing.
        Date::setTestNow('2026-12-11 12:00:00');
        $swept = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(1, $swept->checkLogsPurged);
        self::assertGreaterThanOrEqual(1, $swept->runLogsPurged);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, RunEvent::query()->findOrFail($event->id)->redacted_payload);
        self::assertSame(RunRetentionSweep::CHECK_LOG_TOMBSTONE, CheckResultRecord::query()->findOrFail($check->id)->redacted_output);
        self::assertSame(0, DB::table('run_events')->where('run_id', $run->id)->where('redacted_payload', 'like', '%GEFAELSCHT%')->count());
    }

    /** AC-10/AC-13: the retention migration binds every row stored before the contract exactly once, under the trusted configuration of that upgrade; a later raise neither shows the legacy text again nor postpones its deletion, and the retention run completes only the fingerprint. */
    public function test_the_retention_migration_binds_legacy_rows_once_under_the_upgrade_configuration(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-031-migrate-'.bin2hex(random_bytes(6)).'.sqlite';
        self::assertNotFalse(touch($file));
        $this->rolloutDatabase = $file;
        config(['database.connections.sqlite.database' => $file]);
        DB::purge('sqlite');
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());
        [$run, $project, $operator] = $this->observedRun('AI6-031-MIGRATE');
        $check = $this->seedObservedCheckResult($run, 'ROHDATEN-MIGRATION-1');
        $event = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, 'ROHDATEN-MIGRATION-2', 'ai6-031-migrate-event');
        $this->app->make(RunOrchestrator::class)->failRun($run->id);

        // The state before the retention contract: rolling back exactly this
        // migration removes the binding columns, so every row is a legacy row.
        self::assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1]), Artisan::output());
        self::assertFalse(Schema::hasColumn('run_events', 'retention_expires_at'));
        self::assertFalse(Schema::hasColumn('check_results', 'fingerprint'));

        // The upgrade runs under this trusted configuration, and only this one binds.
        config(['ai6.retention.run_logs.max_days' => 45, 'ai6.retention.check_logs.max_days' => 20]);
        self::assertSame(0, Artisan::call('migrate'), Artisan::output());
        $boundEvent = RunEvent::query()->findOrFail($event->id);
        self::assertSame('2026-10-17 12:00:00', $boundEvent->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(strlen('ROHDATEN-MIGRATION-2'), $boundEvent->retention_size_bytes);
        self::assertNull($boundEvent->fingerprint, 'The keyless migration role binds no fingerprint.');
        self::assertNull($boundEvent->retention_deleted_at);
        $boundCheck = CheckResultRecord::query()->findOrFail($check->id);
        self::assertSame('2026-09-22 12:00:00', $boundCheck->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(strlen($check->redacted_output), $boundCheck->retention_size_bytes);
        self::assertNull($boundCheck->fingerprint);
        self::assertSame(0, DB::table('run_events')->whereNull('retention_expires_at')->count(), 'Every legacy event is bound.');
        self::assertSame(0, DB::table('check_results')->whereNull('retention_expires_at')->count());

        // Raised afterwards and past the bound expiry: the legacy text is
        // neither shown again nor kept longer.
        config(['ai6.retention.run_logs.max_days' => 3650, 'ai6.retention.check_logs.max_days' => 3650]);
        Date::setTestNow('2026-10-17 12:00:00');
        $view = $this->actingAs($operator)->get(route('projects.runs.show', [$project, $run->id]));
        $view->assertOk();
        $view->assertSee('data-retention-expired="run_logs"', false);
        $view->assertSee('data-retention-expired="check_logs"', false);
        $view->assertDontSee('ROHDATEN-MIGRATION');
        $swept = $this->app->make(RunRetentionSweep::class)->sweep();
        self::assertSame(1, $swept->checkLogsPurged);
        self::assertGreaterThanOrEqual(1, $swept->runLogsPurged);
        self::assertSame(0, $swept->failed);
        $generator = $this->app->make(RedactionFingerprintGenerator::class);
        $eventTombstone = RunEvent::query()->findOrFail($event->id);
        self::assertSame(RunRetentionSweep::RUN_LOG_TOMBSTONE, $eventTombstone->redacted_payload);
        self::assertSame('2026-10-17 12:00:00', $eventTombstone->retention_expires_at?->format('Y-m-d H:i:s'), 'The tombstone keeps the expiry the migration bound.');
        self::assertSame('app-key-v1', $eventTombstone->fingerprint_key_id);
        self::assertSame($generator->generate(RedactionMatchType::SECRET, new RedactionContext((string) $run->project_id, $run->id, 'run-log'), 'ROHDATEN-MIGRATION-2')->value, $eventTombstone->fingerprint);
        $checkTombstone = CheckResultRecord::query()->findOrFail($check->id);
        self::assertSame(RunRetentionSweep::CHECK_LOG_TOMBSTONE, $checkTombstone->redacted_output);
        self::assertSame('2026-09-22 12:00:00', $checkTombstone->retention_expires_at?->format('Y-m-d H:i:s'));
        self::assertSame($generator->generate(RedactionMatchType::SECRET, new RedactionContext((string) $run->project_id, $run->id, 'check-log'), $check->redacted_output)->value, $checkTombstone->fingerprint);
        self::assertSame(0, DB::table('run_events')->where('run_id', $run->id)->where('redacted_payload', 'like', '%ROHDATEN-MIGRATION%')->count());
        self::assertSame(0, DB::table('check_results')->where('run_id', $run->id)->where('redacted_output', 'like', '%ROHDATEN-MIGRATION%')->count());
    }

    /** AC-13: two processes with overlapping rings and different active keys record the same message during a staggered key rollout — exactly one row results, under the active key of the process that wrote first: the recording is serialized per run, so the second process is refused while the first one records and finds that row under its own ring afterwards. */
    public function test_two_processes_with_different_active_keys_record_the_same_message_as_exactly_one_event(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        // Two processes share one database file, never the in-memory database
        // of the test process: process B works on its own connection to it.
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-031-rollout-'.bin2hex(random_bytes(6)).'.sqlite';
        self::assertNotFalse(touch($file));
        $this->rolloutDatabase = $file;
        config(['database.connections.sqlite.database' => $file]);
        DB::purge('sqlite');
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());
        [$run] = $this->observedRun('AI6-031-ROLLOUT');
        $message = 'Gestaffelter Rollout mit ROHDATEN-ROLL-5.';
        $keyOne = $this->app->make(RedactionKeyring::class)->activeKey();
        $entries = ['app-key-v1' => ['version' => 1, 'key' => $keyOne], 'key-v2' => ['version' => 2, 'key' => str_repeat("\x07", 32)]];
        $database = config('database.connections.sqlite');
        self::assertIsArray($database);
        config(['database.connections.sqlite_second' => ['busy_timeout' => 300] + $database]);
        // Process A has already rolled over to key v2; process B still signs
        // with key v1. Both rings hold both keys.
        $this->bindKeyring(new RedactionKeyring('key-v2', $entries));

        $interleaved = false;
        $refused = null;
        $other = null;
        DB::listen(function ($query) use (&$interleaved, &$refused, &$other, $entries, $run, $message): void {
            if ($interleaved || ! str_contains($query->sql, 'from "run_events"') || ! str_contains($query->sql, '"event_key" in (')) {
                return;
            }
            $interleaved = true;
            // Process B records the same message right after process A's
            // lookup found nothing — while A still holds the run's event log
            // for writing, so B's own recording cannot begin.
            DB::setDefaultConnection('sqlite_second');
            $this->bindKeyring(new RedactionKeyring('app-key-v1', $entries));
            try {
                $other = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);
            } catch (QueryException $exception) {
                $refused = $exception->getMessage();
            } finally {
                DB::setDefaultConnection('sqlite');
                $this->bindKeyring(new RedactionKeyring('key-v2', $entries));
            }
        });
        $first = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);

        self::assertTrue($interleaved, 'The interleaving actually happened.');
        self::assertNull($other, 'Process B recorded nothing while process A was recording.');
        self::assertIsString($refused);
        self::assertStringContainsString('database is locked', $refused);
        self::assertStringStartsWith(RunOrchestrator::EVENT_IDENTITY_PREFIX.'key-v2:2:', (string) $first->event_key, 'The identity is bound to the active key of the process that wrote it, never to the oldest key of the ring.');
        self::assertSame(1, DB::table('run_events')->where('run_id', $run->id)->where('redacted_payload', 'like', '%ROHDATEN-ROLL%')->count());

        // Process B retries after A committed: it finds A's row under the key
        // its own ring still holds as a non-active key, and writes nothing.
        DB::setDefaultConnection('sqlite_second');
        $this->bindKeyring(new RedactionKeyring('app-key-v1', $entries));
        try {
            $second = $this->app->make(RunOrchestrator::class)->recordStepEvent($run->id, 'implement', ExecutionJobState::RUNNING, $message);
        } finally {
            DB::setDefaultConnection('sqlite');
            $this->bindKeyring(new RedactionKeyring('key-v2', $entries));
        }
        self::assertSame($first->id, $second->id);
        self::assertSame(1, DB::table('run_events')->where('run_id', $run->id)->where('redacted_payload', 'like', '%ROHDATEN-ROLL%')->count());
    }

    private ?string $rolloutDatabase = null;

    #[After]
    public function removeRolloutDatabase(): void
    {
        if ($this->rolloutDatabase === null) {
            return;
        }
        DB::purge('sqlite');
        DB::purge('sqlite_second');
        foreach ([$this->rolloutDatabase, $this->rolloutDatabase.'-wal', $this->rolloutDatabase.'-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->rolloutDatabase = null;
    }

    private function bindKeyring(RedactionKeyring $keyring): void
    {
        $this->app->instance(RedactionKeyring::class, $keyring);
        foreach ([RedactionFingerprintGenerator::class, Redactor::class, RunArtifactStore::class, RunOrchestrator::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    private function withoutTrigger(string $name, callable $action): void
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        self::assertIsObject($row);
        self::assertIsString($row->sql);
        DB::unprepared('DROP TRIGGER '.$name);
        try {
            $action();
        } finally {
            DB::unprepared($row->sql);
        }
    }

    /** @return list<string> */
    private function filesUnder(string $root): array
    {
        $files = [];
        if (! is_dir($root)) {
            return $files;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
