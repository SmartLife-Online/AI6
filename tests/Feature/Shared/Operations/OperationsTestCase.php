<?php

namespace Tests\Feature\Shared\Operations;

use App\AI6\Auth\AuthenticationSession;
use App\AI6\Auth\Models\LoginConfirmation;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\Models\UserSession;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\RunArtifactRoot;
use App\AI6\Runs\RunArtifactStore;
use App\AI6\Runs\RunOrchestrator;
use App\AI6\Shared\Redaction\RedactionFingerprintGenerator;
use App\AI6\Shared\Redaction\RedactionKeyring;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Auth\SessionGuard;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\Runs\BuildsObservedRunFixture;
use Tests\Feature\Tickets\TicketUiTestCase;

abstract class OperationsTestCase extends TicketUiTestCase
{
    use BuildsObservedRunFixture;

    protected string $root;

    protected string $backup;

    protected string $databaseFile;

    protected string $lastOutput = '';

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-26 12:00:00');
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/ai6-049-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $this->backup = $this->root.'/backup';
        self::assertTrue(mkdir($this->root.'/database', 0700));
        $this->databaseFile = $this->root.'/database/database.sqlite';
        DB::connection()->statement('VACUUM INTO ?', [$this->databaseFile]);
        DB::purge();
        $this->observedArtifactRoot = $this->root.'/run-artifacts';
        self::assertTrue(mkdir($this->observedArtifactRoot, 0700));
        self::assertTrue(mkdir($this->root.'/managed/credentials/deploy-keys', 0700, true));
        config([
            'database.connections.sqlite.database' => $this->databaseFile,
            'ai6.runtime_role' => 'worker',
            'ai6.run_artifacts.root' => $this->observedArtifactRoot,
            'ai6.control_operations.managed_root' => $this->root.'/managed',
            'ai6.control_operations.key_root' => $this->root.'/managed/credentials/deploy-keys',
            'ai6.control_operations.known_hosts_file' => $this->root.'/managed/credentials/known_hosts',
        ]);
        foreach ([RunArtifactRoot::class, RunArtifactStore::class, ControlOperationConfiguration::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
        $this->bindOperationsKeyring(new RedactionKeyring('backup-key-v1', ['backup-key-v1' => ['version' => 1, 'key' => random_bytes(32)]]));
        file_put_contents($this->root.'/managed/credentials/deploy-keys/project.key', random_bytes(64));
        chmod($this->root.'/managed/credentials/deploy-keys/project.key', 0600);
        file_put_contents($this->root.'/managed/credentials/known_hosts', 'git.example.test ssh-ed25519 '.base64_encode(random_bytes(32))."\n");
        chmod($this->root.'/managed/credentials/known_hosts', 0600);
    }

    protected function tearDown(): void
    {
        DB::purge();
        Date::setTestNow();
        if (isset($this->root) && is_dir($this->root)) {
            $this->makeRemovable($this->root);
            (new Filesystem)->deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    protected function createBackup(): void
    {
        $exit = Artisan::call('ai6:backup', ['target' => $this->backup]);
        $this->lastOutput = Artisan::output();
        self::assertSame(0, $exit, $this->lastOutput);
    }

    protected function restoreBackup(int $expected = 0, ?string $reason = null): void
    {
        $exit = Artisan::call('ai6:restore', ['source' => $this->backup]);
        $this->lastOutput = Artisan::output();
        self::assertSame($expected, $exit, $this->lastOutput);
        if ($reason !== null) {
            self::assertStringContainsString($reason, $this->lastOutput);
        }
    }

    protected function bindOperationsKeyring(RedactionKeyring $ring): void
    {
        $this->app->instance(RedactionKeyring::class, $ring);
        foreach ([RedactionFingerprintGenerator::class, Redactor::class, RunArtifactStore::class, RunOrchestrator::class] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }

    protected function quiesceRun(Run $run): void
    {
        $this->app->make(RunOrchestrator::class)->failRun($run->id);
        // The shared fixture stops at finalizeClaim(), before the Git executor
        // publishes its completion. Supply that missing fixture state as well.
        $operation = ControlOperation::query()->findOrFail($run->status_operation_id);
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id, 'outcome' => 'succeeded',
            'result_binding' => str_repeat('d', 64), 'safe_summary' => 'Abgeschlossener Test-Claim.',
        ]);
        $operation->update([
            'phase' => ControlOperationPhase::DB_FINALIZED, 'state' => ControlOperationState::COMPLETED,
            'completed_at' => Date::now(), 'version' => $operation->version + 1,
        ]);
    }

    protected function seedLogin(User $user): string
    {
        $id = bin2hex(random_bytes(20));
        $guard = Auth::guard('web');
        self::assertInstanceOf(SessionGuard::class, $guard);
        UserSession::query()->create([
            'id' => $id, 'user_id' => $user->id,
            'payload' => base64_encode(serialize([
                $guard->getName() => $user->id,
                AuthenticationSession::STATE_KEY => AuthenticationSession::STATE_AUTHORIZED,
                'ai6.auth.authorized_at' => Date::now()->getTimestamp(),
            ])),
            'last_activity' => Date::now()->getTimestamp(),
        ]);
        LoginConfirmation::query()->create([
            'user_id' => $user->id, 'revision' => 1,
            'code_digest' => hash('sha256', random_bytes(16)),
            'recipient_digest' => hash('sha256', random_bytes(16)),
            'session_digest' => hash('sha256', random_bytes(16)),
            'expires_at' => Date::now()->addMinutes(5), 'attempt_count' => 0,
            'delivery_status' => 'sent', 'delivery_status_changed_at' => Date::now(),
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    protected function manifest(): array
    {
        return json_decode((string) file_get_contents($this->backup.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $manifest */
    protected function writeManifest(array $manifest): void
    {
        file_put_contents($this->backup.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    protected function rehashSnapshot(): void
    {
        $manifest = $this->manifest();
        clearstatcache();
        foreach ($manifest['files'] as &$file) {
            if ($file['path'] === 'database.sqlite') {
                $file['sha256'] = hash_file('sha256', $this->backup.'/database.sqlite');
                $file['size'] = filesize($this->backup.'/database.sqlite');
            }
        }
        unset($file);
        $this->writeManifest($manifest);
    }

    protected function snapshot(): PDO
    {
        return new PDO('sqlite:'.$this->backup.'/database.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array<string, string> */
    protected function liveBytes(): array
    {
        DB::connection()->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        $paths = [$this->databaseFile, $this->root.'/run-artifacts', $this->root.'/managed'];
        $result = [];
        foreach ($paths as $path) {
            foreach (is_dir($path) ? (new Filesystem)->allFiles($path, true) : [$path] as $file) {
                $name = (string) $file;
                $result[$name] = hash_file('sha256', $name);
            }
        }
        ksort($result);

        return $result;
    }

    private function makeRemovable(string $path): void
    {
        if (is_link($path)) {
            return;
        }
        chmod($path, 0700);
        if (is_dir($path)) {
            foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $child) {
                $this->makeRemovable($path.'/'.$child);
            }
        }
    }
}
