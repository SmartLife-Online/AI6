<?php

namespace Tests\Unit\Shared\Redaction;

use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\RunArtifactKind;
use App\AI6\Runs\RunArtifactRetentionState;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\RedactionMatchType;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Runs\BuildsObservedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

/**
 * TC-12 of AI6-031: the tombstone fingerprint is the central project- and
 * run-bound HMAC with key id and version; after the deletion neither the
 * storage reference nor the unkeyed digest of the removed value survives.
 */
final class RunArtifactTombstoneFingerprintTest extends TicketUiTestCase
{
    use BuildsObservedRunFixture;

    public function test_the_same_content_fingerprints_differently_per_project_and_run_and_survives_only_as_keyed_hmac(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-TC12-A');
        $content = '{"raw":"identischer Inhalt"}';
        $first = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, $content);

        [$otherRun] = $this->secondObservedRun('AI6-031-TC12-B');
        $second = $this->storeObservedArtifact($otherRun, RunArtifactKind::PROVIDER_RAW, $content);

        // Same bytes, other run and project: another fingerprint, same key id and version.
        self::assertNotSame($first->fingerprint, $second->fingerprint);
        self::assertSame('app-key-v1', $first->fingerprint_key_id);
        self::assertSame(1, $first->fingerprint_version);
        self::assertSame($first->fingerprint_key_id, $second->fingerprint_key_id);
        self::assertSame($first->fingerprint_version, $second->fingerprint_version);
        self::assertSame(1, preg_match('/\A[0-9a-f]{64}\z/', (string) $first->fingerprint));

        // The stored fingerprint is exactly the central generator's keyed HMAC
        // over project, run, the artifact-kind context and the redacted bytes.
        $expected = $this->app->make(RedactionFingerprintGenerator::class)->generate(
            RedactionMatchType::SECRET,
            new RedactionContext((string) $run->project_id, $run->id, 'run-artifact:provider_raw'),
            $content,
        );
        self::assertSame($expected->value, $first->fingerprint);
        self::assertSame($expected->keyId, $first->fingerprint_key_id);
        self::assertSame($expected->version, $first->fingerprint_version);

        // Never an unkeyed digest, never reproducible without the server key.
        self::assertNotSame(hash('sha256', $content), $first->fingerprint);
        self::assertNotSame($first->digest, $first->fingerprint);
        $foreignKeyring = new RedactionKeyring('other', ['other' => ['version' => 1, 'key' => str_repeat("\x02", 32)]]);
        $foreign = (new RedactionFingerprintGenerator($foreignKeyring))->generate(
            RedactionMatchType::SECRET,
            new RedactionContext((string) $run->project_id, $run->id, 'run-artifact:provider_raw'),
            $content,
        );
        self::assertNotSame($foreign->value, $first->fingerprint);

        // Same content within the same run and kind is deduplicated, not stored twice.
        self::assertSame($first->id, $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, $content)->id);
    }

    public function test_after_deletion_neither_storage_reference_nor_unkeyed_digest_of_the_removed_value_remains(): void
    {
        Date::setTestNow('2026-09-02 12:00:00');
        [$run] = $this->observedRun('AI6-031-TC12-C');
        $content = '{"raw":"wird entfernt"}';
        $artifact = $this->storeObservedArtifact($run, RunArtifactKind::PROVIDER_RAW, $content);
        $digest = hash('sha256', $content);
        self::assertSame($digest, $artifact->digest);
        $path = $this->observedArtifactPath($artifact);
        self::assertIsString($path);
        self::assertFileExists($path);

        self::assertTrue($this->app->make(RunArtifactStore::class)->purge($artifact, Date::now()->addDays(20)));
        self::assertFalse($this->app->make(RunArtifactStore::class)->purge($artifact, Date::now()->addDays(21)), 'A tombstone is purged only once.');

        $row = (array) DB::table('run_artifacts')->where('id', $artifact->id)->first();
        self::assertSame('deleted', $row['retention_state']);
        self::assertNull($row['storage_reference']);
        self::assertNull($row['digest']);
        self::assertFileDoesNotExist($path);
        self::assertNotContains($digest, array_map(static fn (mixed $value): string => (string) (is_scalar($value) ? $value : ''), $row), 'The unkeyed digest is gone from the record.');
        self::assertStringNotContainsString($digest, json_encode($row, JSON_THROW_ON_ERROR));
        self::assertSame($artifact->fingerprint, $row['fingerprint']);
        self::assertSame('app-key-v1', $row['fingerprint_key_id']);
        self::assertSame(1, (int) $row['fingerprint_version']);
        self::assertSame($artifact->size_bytes, (int) $row['size_bytes']);
        self::assertNotNull($row['deleted_at']);
        self::assertNotNull($row['expires_at']);
        self::assertSame(RunArtifactRetentionState::DELETED, RunArtifact::query()->findOrFail($artifact->id)->retention_state);
        self::assertSame(1, DB::table('run_artifacts')->where('run_id', $run->id)->count(), 'No second tombstone table or row appears.');
        self::assertFalse(DB::getSchemaBuilder()->hasTable('run_artifact_tombstones'));
    }
}
