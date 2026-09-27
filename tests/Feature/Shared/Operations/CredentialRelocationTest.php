<?php

namespace Tests\Feature\Shared\Operations;

use App\AI6\Projects\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class CredentialRelocationTest extends OperationsTestCase
{
    public function test_legacy_reference_moves_only_after_the_files_and_is_idempotent(): void
    {
        [$project, $old, $new] = $this->legacyProject();
        $before = $project->refresh()->getAttributes();
        $bytes = file_get_contents($old);
        self::assertSame(0, Artisan::call('ai6:relocate-credentials', ['--check' => true]), Artisan::output());
        self::assertSame($before, $project->fresh()->getAttributes());
        self::assertSame(1, Artisan::call('ai6:relocate-credentials'));
        self::assertSame($before, $project->fresh()->getAttributes());
        self::assertSame(1, Artisan::call('ai6:backup', ['target' => $this->backup]));
        self::assertStringContainsString('backup_credentials_relocation_required', Artisan::output());
        self::assertDirectoryDoesNotExist($this->backup);
        self::assertTrue(mkdir(dirname($new), 0700));
        self::assertTrue(rename($old, $new));

        self::assertSame(0, Artisan::call('ai6:relocate-credentials'));
        self::assertSame('Übernahme abgeschlossen: 1 Projektreferenzen.', trim(Artisan::output()));
        $after = $project->fresh()->getAttributes();
        self::assertSame($new, $after['deploy_key_reference']);
        $after['deploy_key_reference'] = $old;
        self::assertSame($before, $after);
        self::assertSame($bytes, file_get_contents($new));
        self::assertSame(0, Artisan::call('ai6:relocate-credentials'));
        self::assertStringContainsString('0 Projektreferenzen', Artisan::output());
        $this->createBackup();
        $this->restoreBackup();
        self::assertSame($new, $project->fresh()->deploy_key_reference);
        self::assertSame($bytes, file_get_contents($new));
    }

    public function test_database_failure_rolls_back_all_reference_updates(): void
    {
        [, $old, $new] = $this->legacyProject();
        self::assertTrue(mkdir(dirname($new), 0700));
        self::assertTrue(rename($old, $new));
        [$second, $old, $new] = $this->legacyProject();
        self::assertTrue(mkdir(dirname($new), 0700));
        self::assertTrue(rename($old, $new));
        $before = DB::table('projects')->orderBy('id')->get()->toJson();
        DB::unprepared('CREATE TRIGGER fail_relocation BEFORE UPDATE OF deploy_key_reference ON projects WHEN OLD.id = '.(int) $second->id." BEGIN SELECT RAISE(ABORT, 'fixture'); END");

        self::assertSame(1, Artisan::call('ai6:relocate-credentials'));
        self::assertStringContainsString('credentials_failed', Artisan::output());
        self::assertSame($before, DB::table('projects')->orderBy('id')->get()->toJson());
    }

    public function test_missing_key_or_unexpected_reference_leaves_every_project_unchanged(): void
    {
        [$first, $old, $new] = $this->legacyProject();
        self::assertTrue(mkdir(dirname($new), 0700));
        self::assertTrue(rename($old, $new));
        [$second, $missing] = $this->legacyProject();
        self::assertTrue(unlink($missing));
        $before = DB::table('projects')->orderBy('id')->get()->toJson();
        self::assertSame(1, Artisan::call('ai6:relocate-credentials'));
        self::assertStringContainsString('credentials_key_invalid', Artisan::output());
        self::assertSame($before, DB::table('projects')->orderBy('id')->get()->toJson());
        $second->update(['deploy_key_reference' => $this->root.'/outside']);
        self::assertSame(1, Artisan::call('ai6:relocate-credentials', ['--check' => true]));
        self::assertStringContainsString('credentials_reference_invalid', Artisan::output());
        self::assertSame($old, $first->fresh()->deploy_key_reference);
    }

    public function test_relocation_refuses_active_runs_and_the_application_role(): void
    {
        $this->observedRun('AI6-049-RELOCATE');
        self::assertSame(1, Artisan::call('ai6:relocate-credentials', ['--check' => true]));
        self::assertStringContainsString('credentials_state_not_quiescent', Artisan::output());
        config(['ai6.runtime_role' => 'app']);
        self::assertSame(1, Artisan::call('ai6:relocate-credentials'));
        self::assertStringContainsString('credentials_worker_required', Artisan::output());
    }

    /** @return array{Project, string, string} */
    private function legacyProject(): array
    {
        $identifier = bin2hex(random_bytes(16));
        $old = $this->root.'/managed/deploy-keys/'.$identifier.'/id_ed25519';
        $new = (string) config('ai6.control_operations.key_root').'/'.$identifier.'/id_ed25519';
        self::assertTrue(mkdir(dirname($old), 0700, true));
        file_put_contents($old, random_bytes(64));
        chmod($old, 0600);
        $project = Project::query()->create(['name' => 'Credential relocation',
            'remote' => 'git@git.example.test:acme/control.git', 'control_branch' => 'refs/heads/main',
            'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(random_bytes(32)), '='),
            'project_identifier' => $identifier, 'deploy_key_reference' => $old]);

        return [$project, $old, $new];
    }
}
