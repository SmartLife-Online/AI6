<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Auth\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InstallCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ai6-install-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        touch($this->root.'/database.sqlite');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->root.'/database.sqlite',
            'ai6.runtime_role' => 'app',
            'ai6.auth.login_confirmation_email' => 'private-confirmation@example.test',
        ]);
        DB::purge();
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        (new Filesystem)->deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_fresh_install_reports_missing_steps_then_passes_without_writing(): void
    {
        $output = $this->assertReadOnlyInstall(1);
        self::assertStringContainsString('Datenbank und Migrationen: FEHLT', $output);
        self::assertStringContainsString('docker compose up -d init', $output);
        self::assertStringContainsString('Erster Administrator: FEHLT', $output);
        self::assertStringContainsString('ai6:create-admin', $output);

        $this->migrate();
        $this->administrator();
        $output = $this->assertReadOnlyInstall(0);
        $previous = -1;
        foreach (['APP_KEY: OK', 'Datenbank und Migrationen: OK', 'Erster Administrator: OK', 'Login-Bestätigungsadresse: OK', 'Schlüsselring:', 'Sicherheitsprofil: strict'] as $step) {
            $position = strpos($output, $step);
            self::assertNotFalse($position, $step);
            self::assertGreaterThan($previous, $position);
            $previous = $position;
        }
        self::assertStringContainsString('ai6:provider login <alias>', $output);
        self::assertStringNotContainsString('Nächster Schritt:', $output);
        self::assertStringNotContainsString('ai6:create-admin', $output);
        self::assertStringNotContainsString('private-confirmation@example.test', $output);
        self::assertStringNotContainsString((string) config('app.key'), $output);
    }

    #[DataProvider('missingSteps')]
    public function test_each_missing_required_step_fails_without_writing(string $step): void
    {
        $this->migrate();
        $user = $this->administrator();
        $label = match ($step) {
            'key' => 'APP_KEY',
            'email' => 'Login-Bestätigungsadresse',
            'migration' => 'Datenbank und Migrationen',
            default => 'Erster Administrator',
        };
        match ($step) {
            'key' => config(['app.key' => '', 'ai6.redaction.keys' => ['test-v1' => ['version' => 1, 'key' => 'base64:'.base64_encode(str_repeat('k', 32))]], 'ai6.redaction.active_key_id' => 'test-v1']),
            'email' => config(['ai6.auth.login_confirmation_email' => '']),
            'migration' => DB::table('migrations')->where('id', DB::table('migrations')->max('id'))->delete(),
            'member' => $user->update(['is_global_admin' => false]),
            'inactive' => $user->update(['is_active' => false]),
            default => throw new \InvalidArgumentException('Unknown installation fixture step.'),
        };
        $output = $this->assertReadOnlyInstall(1);
        self::assertStringContainsString($label.': FEHLT', $output);
        self::assertSame(1, substr_count($output, 'Nächster Schritt:'));
        if ($step === 'key') {
            self::assertStringContainsString('APP_KEY=base64:$(openssl rand -base64 32)', $output);
        }
        if (in_array($step, ['member', 'inactive'], true)) {
            self::assertStringContainsString('Bootstrap abgeschlossen', $output);
            self::assertStringContainsString('bestehendes Administratorkonto reaktivieren', $output);
            self::assertStringNotContainsString('ai6:create-admin', $output);
        }
    }

    /** @return list<array{string}> */
    public static function missingSteps(): array
    {
        return [['key'], ['email'], ['migration'], ['member'], ['inactive']];
    }

    public function test_production_without_a_ring_stops_in_bootstrap_before_install(): void
    {
        $source = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->useEnvironmentPath($argv[1]);
exit($app->handleCommand(new Symfony\Component\Console\Input\ArrayInput(['command' => 'ai6:install'])));
PHP;
        $process = new Process([PHP_BINARY, '-r', $source, $this->root], base_path(), [
            'APP_ENV' => 'production', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'AI6_RUNTIME_ROLE' => 'app', 'AI6_REDACTION_KEYS' => '',
        ]);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        self::assertNotSame(0, $process->getExitCode(), $output);
        self::assertStringContainsString('AI6_REDACTION_KEYS', $output);
        self::assertStringNotContainsString('Nächster Schritt:', $output);
        self::assertStringNotContainsString(base64_encode(str_repeat('k', 32)), $output);
    }

    private function migrate(): void
    {
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
    }

    private function administrator(): User
    {
        return User::query()->create(['name' => 'Test', 'email' => 'admin@example.test', 'password' => 'synthetic-password', 'is_active' => true, 'is_global_admin' => true]);
    }

    private function assertReadOnlyInstall(int $expected): string
    {
        // Opening SQLite's WAL reader creates transient lock files. Compare the
        // durable files with both connections closed, and writes on the live one.
        DB::select('select name from sqlite_schema');
        DB::disconnect();
        $files = $this->fileHashes();
        $before = DB::selectOne('select total_changes() as changes');
        $exit = Artisan::call('ai6:install');
        $output = Artisan::output();
        self::assertSame($expected, $exit, $output);
        self::assertEquals($before, DB::selectOne('select total_changes() as changes'));
        DB::disconnect();
        self::assertSame($files, $this->fileHashes());

        return $output;
    }

    /** @return array<string, string|false> */
    private function fileHashes(): array
    {
        $hashes = [];
        foreach ((new Filesystem)->allFiles($this->root) as $file) {
            $hashes[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }

        return $hashes;
    }
}
