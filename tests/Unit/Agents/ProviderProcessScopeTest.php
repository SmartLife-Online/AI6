<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\CredentialProjectionException;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Shared\Process\AgentProcessScope;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use App\AI6\Shared\Redaction\RedactionContext;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/** TC-02/TC-05: an actual process, filesystem namespace and read-only mount. */
final class ProviderProcessScopeTest extends TestCase
{
    private string $root;

    private ExecutionHome $home;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The provider namespace proof requires the Linux runtime.');
        }
        $this->root = sys_get_temp_dir().'/ai6-provider-scope-'.bin2hex(random_bytes(8));
        foreach (['store', 'report', 'presence', 'private', 'input/home/auth', 'input/workspace', 'input/instructions', 'input/runtime', 'output/result', 'output/artifacts', 'output/patch'] as $path) {
            self::assertTrue(mkdir($this->root.'/'.$path, 0700, true));
        }
        config(['ai6.runtime_role' => 'agent', 'ai6.provider_onboarding.store_root' => $this->root.'/store',
            'ai6.provider_onboarding.report_root' => $this->root.'/report', 'ai6.provider_onboarding.presence_root' => $this->root.'/presence',
            'ai6.provider_onboarding.private_root' => $this->root.'/private', 'ai6.process.policies.control.working_roots' => [$this->root]]);
        $boot = bin2hex(random_bytes(16));
        file_put_contents($this->root.'/presence/boot-id', $boot);
        file_put_contents($this->root.'/presence/heartbeat.json', json_encode(['boot_id' => $boot, 'recorded_at' => time()], JSON_THROW_ON_ERROR));
        app(ProviderCredentialStore::class)->replace('github_copilot_cli', 'synthetic-test-token');
        $this->app->forgetInstance(ProcessPolicyRegistry::class);
        $this->app->forgetInstance(ControlProcessRunner::class);
        $this->home = new ExecutionHome($this->root.'/input', $this->root.'/output', $this->root.'/input/workspace',
            $this->root.'/input/home', $this->root.'/input/instructions', $this->root.'/input/runtime/profile.json',
            $this->root.'/input/home/auth', $this->root.'/output/result', $this->root.'/output/artifacts', $this->root.'/output/patch');
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_only_the_selected_auth_projection_is_visible_and_all_writes_fail(): void
    {
        $store = app(ProviderCredentialStore::class);
        foreach (['store/foreign', 'private/login-bait', 'private/projection-bait', 'report/bait', 'presence/supervisor-bait'] as $bait) {
            file_put_contents($this->root.'/'.$bait, 'private-bait');
        }
        $forbidden = array_map(fn (string $path): string => $this->root.'/'.$path,
            ['store/github_copilot_cli/token', 'store/foreign', 'private/login-bait', 'private/projection-bait', 'report/bait', 'presence/supervisor-bait']);
        $revision = app(CredentialRevisionRegistry::class)->revision('github_copilot_cli');
        $projection = null;
        $store->withProjection($this->home, 'github_copilot_cli', $revision, function (ExecutionHome $home) use ($forbidden, &$projection): void {
            $projection = $home->authDirectory;
            $code = '$auth=$argv[1]; $forbidden=array_slice($argv,2); echo json_encode(['
                .'"auth"=>@file_get_contents($auth)==="synthetic-test-token",'
                .'"write"=>@file_put_contents($auth,"changed")!==false,"chmod"=>@chmod($auth,0600),'
                .'"leaks"=>array_map(static fn($p)=>@file_get_contents($p)!==false,$forbidden),'
                .'"parent_store"=>@file_get_contents("/proc/1/root".$forbidden[0])!==false]);';
            $result = app(ControlProcessRunner::class)->run(new ProcessRequest([PHP_BINARY, '-r', $code, $home->authDirectory.'/token', ...$forbidden],
                $home->workspace, [], [], new RedactionContext('test', null, 'namespace')));
            self::assertTrue($result->succeeded(), $result->errorOutput);
            self::assertSame(['auth' => true, 'write' => false, 'chmod' => false, 'leaks' => array_fill(0, count($forbidden), false), 'parent_store' => false], json_decode($result->output, true, flags: JSON_THROW_ON_ERROR));
        });
        self::assertNotNull($projection);
        self::assertDirectoryDoesNotExist($projection);
        self::assertSame('synthetic-test-token', file_get_contents($this->root.'/store/github_copilot_cli/token'));
        self::assertSame([], array_values(array_diff(scandir($this->home->authDirectory), ['.', '..'])), 'The worker home never received credential bytes.');

        // The same leak oracle must detect a deliberately open test boundary.
        config(['ai6.runtime_role' => 'worker']);
        $open = app(ControlProcessRunner::class)->run(new ProcessRequest([PHP_BINARY, '-r', 'echo is_readable($argv[1]) ? "leak" : "closed";', $forbidden[0]],
            $this->home->workspace, [], [], new RedactionContext('test', null, 'negative-control')));
        self::assertSame('leak', $open->output);
    }

    public function test_sandbox_scratch_is_private_fresh_for_each_process_and_does_not_unseal_home(): void
    {
        app(ProviderCredentialStore::class)->withProjection($this->home, 'github_copilot_cli',
            app(CredentialRevisionRegistry::class)->revision('github_copilot_cli'), function (ExecutionHome $home): void {
                $code = '$scratch=$argv[1]; echo json_encode(['
                    .'"mode"=>fileperms($scratch)&0777,"owned"=>fileowner($scratch)===posix_geteuid(),'
                    .'"fresh"=>!file_exists($scratch."/bait"),"write"=>file_put_contents($scratch."/bait","private")===7,'
                    .'"home_write"=>@file_put_contents($argv[2]."/bait","forbidden")!==false]);';
                for ($invocation = 0; $invocation < 2; $invocation++) {
                    $result = app(ControlProcessRunner::class)->run(new ProcessRequest(
                        [PHP_BINARY, '-r', $code, AgentProcessScope::SANDBOX_WORK_DIRECTORY, $home->home],
                        $home->workspace, [], [], new RedactionContext('test', null, 'sandbox-scratch')));
                    self::assertTrue($result->succeeded(), $result->errorOutput);
                    self::assertSame(['mode' => 0700, 'owned' => true, 'fresh' => true, 'write' => true, 'home_write' => false],
                        json_decode($result->output, true, flags: JSON_THROW_ON_ERROR));
                }
                self::assertFileDoesNotExist($home->home.'/bait');
            });
    }

    public function test_errors_and_rotated_generations_cannot_reuse_or_leave_a_projection(): void
    {
        $revision = app(CredentialRevisionRegistry::class)->revision('github_copilot_cli');
        $projection = null;
        $cancelled = false;
        try {
            app(ProviderCredentialStore::class)->withProjection($this->home, 'github_copilot_cli', $revision,
                function (ExecutionHome $home) use (&$projection): void {
                    $projection = $home->authDirectory;
                    throw new \RuntimeException('Synthetic cancellation.');
                });
        } catch (\RuntimeException $exception) {
            $cancelled = true;
            self::assertSame('Synthetic cancellation.', $exception->getMessage());
        } finally {
            if (! $cancelled) {
                self::fail('The cancelled operation returned successfully.');
            }
        }
        self::assertNotNull($projection);
        self::assertDirectoryDoesNotExist($projection);
        app(ProviderCredentialStore::class)->replace('github_copilot_cli', null);
        $this->expectException(CredentialProjectionException::class);
        app(ProviderCredentialStore::class)->withProjection($this->home, 'github_copilot_cli', $revision,
            static function (): never {
                self::fail('A stale projection started.');
            });
    }

    public function test_expired_report_does_not_cancel_a_running_process_or_keep_revoked_credentials_alive(): void
    {
        $store = app(ProviderCredentialStore::class);
        $generation = app(CredentialRevisionRegistry::class)->revision('github_copilot_cli');
        $store->withProjection($this->home, 'github_copilot_cli', $generation, function (ExecutionHome $home) use ($store, $generation): void {
            $running = app(ControlProcessRunner::class)->start(new ProcessRequest(
                [PHP_BINARY, '-r', 'usleep(2200000); echo "completed";'], $home->workspace, [], [],
                new RedactionContext('test', null, 'expiry'), timeoutSeconds: 15));
            $expired = false;
            $result = $running->wait(function () use ($store, $generation, &$expired): void {
                if (! $expired) {
                    $reports = app(ProviderCapabilityReport::class);
                    $store->locked(fn () => $store->publish('github_copilot_cli', $generation, [], time() - 3600, $reports->boot()));
                    $expired = true;
                }
            });
            self::assertTrue($expired);
            self::assertTrue($result->succeeded());
            self::assertSame('completed', $result->output);
        });
        self::assertSame($generation, app(CredentialRevisionRegistry::class)->revision('github_copilot_cli', false));
        $store->replace('github_copilot_cli', null);
        self::assertNotSame($generation, app(CredentialRevisionRegistry::class)->revision('github_copilot_cli', false));
    }

    public function test_rotation_cancels_a_running_process_and_cleans_its_projection(): void
    {
        $store = app(ProviderCredentialStore::class);
        $generation = app(CredentialRevisionRegistry::class)->revision('github_copilot_cli');
        $running = null;
        $projection = null;
        $rotated = false;
        try {
            $store->withProjection($this->home, 'github_copilot_cli', $generation,
                function (ExecutionHome $home) use ($store, &$running, &$projection, &$rotated): void {
                    $projection = $home->authDirectory;
                    $running = app(ControlProcessRunner::class)->start(new ProcessRequest(
                        [PHP_BINARY, '-r', 'while (true) { usleep(100000); }'], $home->workspace, [], [],
                        new RedactionContext('test', null, 'rotation'), timeoutSeconds: 15));
                    $running->wait(function () use ($store, &$rotated): void {
                        if (! $rotated) {
                            $reports = app(ProviderCapabilityReport::class);
                            $store->locked(fn () => $store->publish('github_copilot_cli', $store->generation('github_copilot_cli'), [], time() - 3600, $reports->boot()));
                            $store->replace('github_copilot_cli', null);
                            $rotated = true;
                        }
                    });
                });
            self::fail('The revoked process completed.');
        } catch (CredentialProjectionException) {
            self::assertTrue($rotated);
            self::assertNotNull($running);
            self::assertFalse($running->running());
            self::assertNotNull($projection);
            self::assertDirectoryDoesNotExist($projection);
            self::assertNotSame($generation, app(CredentialRevisionRegistry::class)->revision('github_copilot_cli'));
        }
    }

    public function test_missing_namespace_refuses_execution_and_removes_the_projection(): void
    {
        config(['ai6.provider_onboarding.bubblewrap_binary' => '/nonexistent/bwrap']);
        $projection = null;
        try {
            app(ProviderCredentialStore::class)->withProjection($this->home, 'github_copilot_cli',
                app(CredentialRevisionRegistry::class)->revision('github_copilot_cli'), function (ExecutionHome $home) use (&$projection): void {
                    $projection = $home->authDirectory;
                    app(ControlProcessRunner::class)->start(new ProcessRequest([PHP_BINARY, '-r', 'exit(0);'],
                        $home->workspace, [], [], new RedactionContext('test', null, 'missing-namespace')));
                });
            self::fail('A provider started without its namespace.');
        } catch (ProcessStartRejectedException) {
            self::assertNotNull($projection);
            self::assertDirectoryDoesNotExist($projection);
        }
    }

    public function test_restart_removes_abandoned_private_trees_without_following_links(): void
    {
        $bait = $this->root.'/store/retained';
        file_put_contents($bait, 'retained');
        foreach (['login', 'projection', 'probe'] as $prefix) {
            $directory = $this->root.'/private/'.$prefix.'-'.bin2hex(random_bytes(16));
            mkdir($directory, 0700);
            file_put_contents($directory.'/auth-bait', 'ephemeral');
            symlink($bait, $directory.'/foreign');
            chmod($directory, 0500);
        }
        app(ProviderCredentialStore::class)->recover();
        self::assertSame(['.', '..'], scandir($this->root.'/private'));
        self::assertSame('retained', file_get_contents($bait));
    }
}
