<?php

namespace Tests\Feature\Agents;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CapabilityStatus;
use App\AI6\Agents\CodexCliConfiguration;
use App\AI6\Agents\Console\ProviderCommand;
use App\AI6\Agents\CredentialProjectionException;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\ProviderCapabilityPublisher;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Agents\ProviderLogin;
use App\AI6\Agents\ProviderOnboarding;
use App\AI6\Shared\Doctor\CodexCliDoctorCheck;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Fixtures\Agents\BuildsProviderOnboarding;
use Tests\Fixtures\Agents\FakeCodexBinary;
use Tests\Fixtures\Agents\FakeCopilotBinary;
use Tests\Fixtures\Agents\FakeGrokBinary;
use Tests\TestCase;

final class ProviderLoginTest extends TestCase
{
    use BuildsProviderOnboarding;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Native provider login requires the Linux namespace runtime.');
        }
        $this->createOnboardingFixture();
    }

    public function test_cli_logins_commit_only_the_minimal_auth_file_and_do_not_release_profiles(): void
    {
        config(['ai6.codex.binary' => FakeCodexBinary::create($this->onboardingRoot.'/cli-fixtures')]);
        self::assertSame(0, $this->command('login', 'codex_cli')->getStatusCode());
        $store = $this->onboardingRoot.'/store/codex_cli';
        self::assertSame(['.', '..', 'auth.json', 'generation'], scandir($store));
        self::assertSame('{"tokens":{"access_token":"synthetic-codex-token"}}', file_get_contents($store.'/auth.json'));
        self::assertFalse(app(AgentProfileRegistry::class)->get('codex-gpt-5.6-terra')->capabilityStatus->selectable());

        config(['ai6.copilot.binary' => FakeCopilotBinary::create($this->onboardingRoot.'/cli-fixtures')]);
        $command = $this->command('login', 'github_copilot_cli', ['synthetic-copilot-token']);
        self::assertSame(0, $command->getStatusCode(), $command->getDisplay());
        self::assertStringNotContainsString('synthetic-copilot-token', $command->getDisplay());
        self::assertSame('synthetic-copilot-token', file_get_contents($this->onboardingRoot.'/store/github_copilot_cli/token'));
        self::assertSame(['.', '..', 'generation', 'token'], scandir($this->onboardingRoot.'/store/github_copilot_cli'));
        config(['ai6.grok.binary' => FakeGrokBinary::create($this->onboardingRoot.'/cli-fixtures')]);
        $command = $this->command('login', 'grok_cli', ['synthetic-grok-token']);
        self::assertSame(0, $command->getStatusCode(), $command->getDisplay());
        self::assertStringNotContainsString('synthetic-grok-token', $command->getDisplay());
        self::assertSame('synthetic-grok-token', file_get_contents($this->onboardingRoot.'/store/grok_cli/token'));
        self::assertSame(['.', '..', 'generation', 'token'], scandir($this->onboardingRoot.'/store/grok_cli'));
        self::assertSame(['.', '..'], scandir($this->onboardingRoot.'/private'));
    }

    public function test_failed_or_cancelled_login_preserves_the_old_store_and_generation(): void
    {
        $store = app(ProviderCredentialStore::class);
        $store->replace('codex_cli', '{"old":"synthetic"}');
        $generation = $store->generation('codex_cli');
        foreach (['login_fail', 'login_cancel'] as $scenario) {
            config(['ai6.codex.binary' => FakeCodexBinary::create($this->onboardingRoot.'/cli-fixtures', $scenario)]);
            try {
                app(ProviderLogin::class)->login('codex_cli', null, static function (): void {}, static fn (): bool => $scenario === 'login_cancel');
                self::fail('An unsuccessful login changed the store.');
            } catch (CredentialProjectionException) {
                self::assertSame($generation, $store->generation('codex_cli'));
                self::assertSame('{"old":"synthetic"}', file_get_contents($this->onboardingRoot.'/store/codex_cli/auth.json'));
                self::assertSame(['.', '..'], scandir($this->onboardingRoot.'/private'));
            }
        }
    }

    public function test_wrong_roles_aliases_and_actions_have_no_effect(): void
    {
        app(ProviderCredentialStore::class)->replace('github_copilot_cli', 'synthetic-copilot-token');
        $generation = app(ProviderCredentialStore::class)->generation('github_copilot_cli');
        foreach (['worker', 'app', 'scheduler', 'checker'] as $role) {
            config(['ai6.runtime_role' => $role]);
            self::assertSame(1, $this->command('logout', 'github_copilot_cli')->getStatusCode());
        }
        config(['ai6.runtime_role' => 'agent']);
        self::assertSame(1, $this->command('logout', 'claude_cli')->getStatusCode());
        self::assertSame(2, $this->command('status', 'github_copilot_cli')->getStatusCode());
        self::assertSame($generation, app(ProviderCredentialStore::class)->generation('github_copilot_cli'));
    }

    public function test_codex_local_catalog_fallback_cannot_supply_live_auth_model_evidence(): void
    {
        $store = app(ProviderCredentialStore::class);
        $store->replace('codex_cli', '{"tokens":{"access_token":"synthetic-codex-token"}}');
        $generation = $store->generation('codex_cli');
        foreach (['models_offline' => false, 'success' => true] as $scenario => $expected) {
            config(['ai6.codex.binary' => FakeCodexBinary::create($this->onboardingRoot.'/cli-fixtures', $scenario)]);
            self::assertSame($expected, app(ProviderLogin::class)->verifyStored('codex_cli', $generation, 'gpt-5.3-codex', 'medium'));
            self::assertSame(['.', '..'], scandir($this->onboardingRoot.'/private'));
        }
        self::assertFalse(app(ProviderLogin::class)->verifyStored('codex_cli', $generation, 'unavailable-model', 'medium'));
    }

    public function test_an_unchecked_profile_reaches_the_native_probe_and_releases_only_its_proven_tuple(): void
    {
        config(['ai6.codex.binary' => FakeCodexBinary::create($this->onboardingRoot.'/cli-fixtures'),
            'ai6.codex.sandbox_proof' => FakeCodexBinary::sandboxProof()]);
        $this->app->forgetInstance(CodexCliConfiguration::class);
        $registry = app(AgentProfileRegistry::class);
        self::assertSame(CapabilityStatus::UNCHECKED, $registry->get('codex-gpt-5.6-terra')->capabilityStatus);
        app(ProviderCredentialStore::class)->replace('codex_cli', '{"tokens":{"access_token":"synthetic-codex-token"}}');
        $probe = app(ExecutionHomeManager::class)->withProbeHome('codex_cli', 'codex-cli-v1',
            fn (ExecutionHome $home) => app(CodexCliDoctorCheck::class)->probeCombination($home));
        self::assertTrue($probe->passed, json_encode($probe->details, JSON_THROW_ON_ERROR));
        app(ProviderCapabilityPublisher::class)->recheck('codex_cli');
        self::assertTrue($registry->supportsProviderSelection('codex_cli', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'medium'));
        self::assertFalse($registry->supportsProviderSelection('codex_cli', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'high'));
        self::assertSame(['.', '..'], scandir($this->onboardingRoot.'/private'));
    }

    public function test_recheck_probes_once_per_alias_and_keeps_the_role_heartbeat_alive(): void
    {
        $binary = FakeCodexBinary::create($this->onboardingRoot.'/cli-fixtures');
        $log = $this->onboardingRoot.'/probe-counts.jsonl';
        $wrapper = $this->onboardingRoot.'/observed-bwrap';
        // Observe at the trusted namespace launcher, before entering the unchanged
        // real bwrap boundary. Record categories only, never arguments or input.
        $script = '#!'.PHP_BINARY."\n<?php\n"
            .'$kind = in_array("--version", $argv, true) ? "version" : (in_array("features", $argv, true) ? "features" : (in_array("models", $argv, true) ? "models" : "status"));'
            .'file_put_contents('.var_export($log, true).', $kind."\n", FILE_APPEND | LOCK_EX);'
            .'usleep(1100000); pcntl_exec("/usr/bin/bwrap", array_slice($argv, 1)); exit(99);';
        file_put_contents($wrapper, $script);
        chmod($wrapper, 0755);
        config(['ai6.codex.binary' => $binary, 'ai6.codex.sandbox_proof' => FakeCodexBinary::sandboxProof(),
            'ai6.provider_onboarding.bubblewrap_binary' => $wrapper]);
        $this->app->forgetInstance(CodexCliConfiguration::class);
        app(ProviderCredentialStore::class)->replace('codex_cli', '{"tokens":{"access_token":"synthetic-codex-token"}}');
        $heartbeats = 0;
        $store = app(ProviderCredentialStore::class);
        $document = app(ProviderCapabilityReport::class)->read('codex_cli');
        self::assertNotNull($document);
        $store->locked(fn () => $store->publish('codex_cli', $document['generation'], $document['rows'], time() - 241, $document['boot_id']));
        $started = microtime(true);
        app(ProviderCapabilityPublisher::class)->due(static function () use (&$heartbeats): void {
            $heartbeats++;
        });
        $lines = file($log, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $counts = array_count_values($lines);
        self::assertSame(['version' => 1, 'features' => 1, 'status' => 1, 'models' => 1], $counts);
        self::assertGreaterThanOrEqual(4, $heartbeats);
        self::assertGreaterThan((microtime(true) - $started) * 3, ProviderOnboarding::seconds('probe_interval_seconds'));
        self::assertTrue(app(AgentProfileRegistry::class)->supportsProviderSelection('codex_cli', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'medium'));
        app(ProviderCapabilityPublisher::class)->due();
        $repeated = file($log, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($repeated);
        self::assertSame($counts, array_count_values($repeated));
    }

    public function test_copilot_requires_the_authenticated_model_catalog_and_keeps_model_policy_separate(): void
    {
        $store = app(ProviderCredentialStore::class);
        $store->replace('github_copilot_cli', 'synthetic-copilot-token');
        $generation = $store->generation('github_copilot_cli');
        foreach (['success' => true, 'model_disabled' => false, 'models_offline' => false,
            'models_wrong_id' => false, 'models_duplicate' => false, 'models_truncated' => false, 'models_multiple' => false] as $scenario => $expected) {
            config(['ai6.copilot.binary' => FakeCopilotBinary::create($this->onboardingRoot.'/cli-fixtures', $scenario)]);
            self::assertSame($expected, app(ProviderLogin::class)->verifyStored('github_copilot_cli', $generation, 'gpt-5.4', 'provider_default'), $scenario);
            self::assertFalse(app(ProviderLogin::class)->verifyStored('github_copilot_cli', $generation, 'claude-sonnet-4.6', 'provider_default'));
            self::assertFalse(app(ProviderLogin::class)->verifyStored('github_copilot_cli', $generation, 'gpt-5.4', 'high'));
            self::assertSame(['.', '..'], scandir($this->onboardingRoot.'/private'));
        }
    }

    public function test_successful_relogin_and_logout_rotate_the_generation(): void
    {
        config(['ai6.codex.binary' => FakeCodexBinary::create($this->onboardingRoot.'/cli-fixtures')]);
        self::assertSame(0, $this->command('login', 'codex_cli')->getStatusCode());
        $store = app(ProviderCredentialStore::class);
        $first = $store->generation('codex_cli');
        self::assertSame(0, $this->command('login', 'codex_cli')->getStatusCode());
        $second = $store->generation('codex_cli');
        self::assertNotSame($first, $second);
        self::assertSame(0, $this->command('logout', 'codex_cli')->getStatusCode());
        self::assertNotSame($second, $store->generation('codex_cli'));
        self::assertFileDoesNotExist($this->onboardingRoot.'/store/codex_cli/auth.json');
        self::assertFalse(app(AgentProfileRegistry::class)->get('codex-gpt-5.6-terra')->capabilityStatus->selectable());
    }

    public function test_grok_requires_a_fresh_authenticated_native_catalog_and_preserves_the_store_on_failure(): void
    {
        $store = app(ProviderCredentialStore::class);
        $store->replace('grok_cli', 'synthetic-grok-token');
        $generation = $store->generation('grok_cli');
        foreach (['models_offline', 'models_foreign_origin', 'models_foreign_auth', 'models_foreign_version',
            'models_old', 'models_future', 'models_missing_default', 'models_disabled', 'models_invalid'] as $scenario) {
            config(['ai6.grok.binary' => FakeGrokBinary::create($this->onboardingRoot.'/cli-fixtures', $scenario)]);
            self::assertFalse(app(ProviderLogin::class)->verifyStored('grok_cli', $generation, 'provider_default', 'provider_default'), $scenario);
            self::assertSame(1, $this->command('login', 'grok_cli', ['synthetic-grok-token'])->getStatusCode(), $scenario);
            self::assertSame($generation, $store->generation('grok_cli'));
            self::assertSame('synthetic-grok-token', file_get_contents($this->onboardingRoot.'/store/grok_cli/token'));
            self::assertSame(['.', '..'], scandir($this->onboardingRoot.'/private'));
        }
        config(['ai6.grok.binary' => FakeGrokBinary::create($this->onboardingRoot.'/cli-fixtures')]);
        self::assertTrue(app(ProviderLogin::class)->verifyStored('grok_cli', $generation, 'provider_default', 'provider_default'));
        self::assertFalse(app(ProviderLogin::class)->verifyStored('grok_cli', $generation, 'missing-model', 'provider_default'));
        self::assertFalse(app(ProviderLogin::class)->verifyStored('grok_cli', $generation, 'provider_default', 'high'));
        foreach ([null, '', "invalid\ninput"] as $input) {
            try {
                app(ProviderLogin::class)->login('grok_cli', $input, static function (): void {}, static fn (): bool => false);
                self::fail('An invalid Grok key was accepted.');
            } catch (CredentialProjectionException) {
                self::assertSame($generation, $store->generation('grok_cli'));
            }
        }
        try {
            app(ProviderLogin::class)->login('grok_cli', 'synthetic-grok-token', static function (): void {}, static fn (): bool => true);
            self::fail('A cancelled Grok login was accepted.');
        } catch (CredentialProjectionException) {
            self::assertSame($generation, $store->generation('grok_cli'));
            self::assertSame(['.', '..'], scandir($this->onboardingRoot.'/private'));
        }
    }

    /** @param list<string> $inputs */
    private function command(string $action, string $alias, array $inputs = []): CommandTester
    {
        $command = app(ProviderCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute(['action' => $action, 'alias' => $alias], ['interactive' => true]);

        return $tester;
    }
}
