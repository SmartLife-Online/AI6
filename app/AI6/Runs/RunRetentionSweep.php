<?php

namespace App\AI6\Runs;

use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionMatchType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The idempotent retention run (SEC-011, plan §10.6).
 *
 * It removes expired raw data at the existing storage boundaries: run logs are
 * the timeline payloads, check logs the redacted check outputs, agent raw
 * output and the other artifacts the run-artifact bytes with their storage
 * objects. Every removal leaves a bound tombstone in the record it came from,
 * never a copy elsewhere: the fixed marker, the binding the record received
 * at its persistence — the central project- and run-bound HMAC fingerprint of
 * the removed redacted text with key id and version, its size and the expiry
 * that applied when it was written — and the deletion time. Due is what that
 * persisted expiry says and nothing else: a later change of the configured
 * days moves no deletion, and a row without a persisted expiry is never
 * derived from the current value — it is named, counted as failed and left
 * untouched, because the retention migration bound every legacy row once
 * under the trusted upgrade configuration and the guards refuse an unbound
 * new one. A migrated legacy row still lacks its fingerprint — the keyless
 * migration role cannot compute one — and receives it with the tombstone,
 * over the redacted text it is about to lose. The event key stays a
 * keyed identity of the event: an explicit business key and a bound
 * `hmac:` identity are kept as they are, and a legacy unkeyed digest of the
 * message — the identity a row carried before the keyed binding — is rebound
 * to the keyed identity over that digest, so no unkeyed message hash remains
 * after the deletion while a redelivered message still finds its tombstone
 * instead of writing the payload again. An active run
 * defers its removals by the central grace period only. Repeating the run
 * over the same data changes nothing.
 */
final readonly class RunRetentionSweep
{
    public const RUN_LOG_TOMBSTONE = '[AI6-RETENTION-TOMBSTONE:run_logs]';

    public const CHECK_LOG_TOMBSTONE = '[AI6-RETENTION-TOMBSTONE:check_logs]';

    public function __construct(
        private RunArtifactStore $artifacts,
        private RetentionPolicy $retention,
        private RedactionFingerprintGenerator $fingerprints,
        private RunLogRetentionBinding $binding,
    ) {}

    public function sweep(?CarbonInterface $now = null): RunRetentionSweepResult
    {
        $now = CarbonImmutable::instance($now ?? Date::now());
        $runs = [];
        $artifactsPurged = 0;
        $runLogsPurged = 0;
        $checkLogsPurged = 0;
        $deferred = 0;

        $failed = 0;
        $artifactIds = RunArtifact::query()
            ->where('retention_state', RunArtifactRetentionState::STORED->value)
            ->where('expires_at', '<=', $now)
            ->orderBy('run_id')->orderBy('sequence')->pluck('id');
        foreach ($artifactIds as $artifactId) {
            $artifact = is_string($artifactId) ? RunArtifact::query()->find($artifactId) : null;
            if (! $artifact instanceof RunArtifact) {
                continue;
            }
            if ($now->lessThan($this->retention->purgeDeadline($artifact->expires_at, $this->runIsActive($artifact->run_id, $runs)))) {
                $deferred++;

                continue;
            }
            // One storage object that cannot be removed must not halt the
            // retention of every other record; it is named, counted and
            // tried again by the next run, because its row stays stored.
            try {
                if ($this->artifacts->purge($artifact, $now)) {
                    $artifactsPurged++;
                }
            } catch (ImplementationImportException $exception) {
                $failed++;
                Log::warning('Run artifact retention deletion failed.', [
                    'artifact_id' => $artifact->id,
                    'run_id' => $artifact->run_id,
                    'reason' => $exception->reason,
                ]);
            }
        }

        // Due is what the persisted expiry says. A row without one cannot be
        // due by any trusted value and is never derived from the current one.
        $failed += $this->reportUnboundRows('run_events');
        $failed += $this->reportUnboundRows('check_results');

        $eventIds = RunEvent::query()
            ->where('retention_expires_at', '<=', $now)
            ->whereNull('retention_deleted_at')
            ->orderBy('id')->pluck('id');
        foreach ($eventIds as $eventId) {
            $event = is_int($eventId) ? RunEvent::query()->find($eventId) : null;
            if (! $event instanceof RunEvent || $event->retention_expires_at === null) {
                continue;
            }
            $run = $this->run($event->run_id, $runs);
            if ($now->lessThan($this->retention->purgeDeadline($event->retention_expires_at, $run instanceof Run && RetentionPolicy::runIsActive($run)))) {
                $deferred++;

                continue;
            }
            $binding = $event->fingerprint === null
                ? $this->binding->fingerprintBinding($event->run_id, RetentionCategory::RUN_LOGS, $event->redacted_payload)
                : [];
            $eventKey = $event->event_key;
            if (is_string($eventKey) && strlen($eventKey) === 64 && ctype_xdigit($eventKey) && strtolower($eventKey) === $eventKey) {
                $eventKey = RunOrchestrator::boundEventIdentity($this->fingerprints->generate(
                    RedactionMatchType::SECRET,
                    new RedactionContext($run instanceof Run ? (string) $run->project_id : '', $event->run_id, RunOrchestrator::LEGACY_EVENT_IDENTITY_CONTEXT),
                    $eventKey,
                ));
            }
            $runLogsPurged += RunEvent::query()->whereKey($event->getKey())
                ->whereNull('retention_deleted_at')
                ->update($binding + [
                    'redacted_payload' => self::RUN_LOG_TOMBSTONE,
                    'event_key' => $eventKey,
                    'retention_deleted_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $checkIds = CheckResultRecord::query()
            ->where('retention_expires_at', '<=', $now)
            ->whereNull('retention_deleted_at')
            ->orderBy('created_at')->orderBy('id')->pluck('id');
        foreach ($checkIds as $checkId) {
            $result = is_string($checkId) ? CheckResultRecord::query()->find($checkId) : null;
            if (! $result instanceof CheckResultRecord || $result->retention_expires_at === null) {
                continue;
            }
            $run = $this->run($result->run_id, $runs);
            if ($now->lessThan($this->retention->purgeDeadline($result->retention_expires_at, $run instanceof Run && RetentionPolicy::runIsActive($run)))) {
                $deferred++;

                continue;
            }
            $binding = $result->fingerprint === null
                ? $this->binding->fingerprintBinding($result->run_id, RetentionCategory::CHECK_LOGS, $result->redacted_output)
                : [];
            $checkLogsPurged += CheckResultRecord::query()->whereKey($result->getKey())
                ->whereNull('retention_deleted_at')
                ->update($binding + [
                    'redacted_output' => self::CHECK_LOG_TOMBSTONE,
                    'retention_deleted_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        return new RunRetentionSweepResult($artifactsPurged, $runLogsPurged, $checkLogsPurged, $deferred, $failed);
    }

    /**
     * A log row without a persisted expiry has no trusted due date: it is
     * neither shown nor derived nor deleted, but named and counted so the
     * operator sees it — such a row can only come from outside the guards.
     */
    private function reportUnboundRows(string $table): int
    {
        $unbound = DB::table($table)->whereNull('retention_expires_at')->whereNull('retention_deleted_at')->orderBy('id')->pluck('run_id', 'id');
        foreach ($unbound as $id => $runId) {
            Log::warning('Run log retention binding missing.', [
                'table' => $table,
                'id' => $id,
                'run_id' => $runId,
                'reason' => 'retention_binding_missing',
            ]);
        }

        return $unbound->count();
    }

    /** @param array<string, Run|null> $cache */
    private function runIsActive(string $runId, array &$cache): bool
    {
        $run = $this->run($runId, $cache);

        return $run instanceof Run && RetentionPolicy::runIsActive($run);
    }

    /** @param array<string, Run|null> $cache */
    private function run(string $runId, array &$cache): ?Run
    {
        if (! array_key_exists($runId, $cache)) {
            $cache[$runId] = Run::query()->find($runId);
        }

        return $cache[$runId];
    }
}
