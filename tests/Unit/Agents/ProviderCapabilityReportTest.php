<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CapabilityStatus;
use App\AI6\Agents\CodexCliConfiguration;
use App\AI6\Agents\CredentialProjectionException;
use App\AI6\Agents\CredentialRevisionRegistry;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Shared\Config\StrictEnumParser;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProviderCapabilityReportTest extends TestCase
{
    private string $root;

    private string $boot;

    private string $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/ai6-provider-'.bin2hex(random_bytes(8));
        foreach (['store', 'reports', 'presence', 'private'] as $name) {
            self::assertTrue(mkdir($this->root.'/'.$name, 0700, true));
        }
        $this->boot = bin2hex(random_bytes(16));
        config(['ai6.runtime_role' => 'agent',
            'ai6.provider_onboarding.store_root' => $this->root.'/store',
            'ai6.provider_onboarding.report_root' => $this->root.'/reports',
            'ai6.provider_onboarding.presence_root' => $this->root.'/presence',
            'ai6.provider_onboarding.private_root' => $this->root.'/private',
            'ai6.codex.binary' => PHP_BINARY, 'ai6.codex.pinned_version' => '0.129.0-alpha.15',
            'ai6.codex.sandbox_proof' => '0.129.0-alpha.15:'.CodexCliConfiguration::runtimePlatform(),
        ]);
        file_put_contents($this->root.'/presence/boot-id', $this->boot);
        file_put_contents($this->root.'/presence/heartbeat.json', json_encode(['boot_id' => $this->boot, 'recorded_at' => time()], JSON_THROW_ON_ERROR));
        // Only the agent owns this synthetic store. Neither a worker fixture nor
        // a provider process writes the report/projection used by the tests.
        app(ProviderCredentialStore::class)->replace('codex_cli', '{"test":"synthetic"}');
        $this->generation = app(CredentialRevisionRegistry::class)->revision('codex_cli');
        $this->publish();
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem)->deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_a_booted_registry_reads_current_tuple_evidence_and_rotation_without_reboot(): void
    {
        $registry = app(AgentProfileRegistry::class);
        self::assertSame(CapabilityStatus::AVAILABLE, $registry->get('codex-gpt-5.6-terra')->capabilityStatus);
        self::assertTrue($registry->supportsProviderSelection('codex_cli', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'medium'));
        self::assertFalse($registry->supportsProviderSelection('codex_cli', AgentRole::QUALITY_REVIEW, 'gpt-5.3-codex', 'medium'));
        self::assertFalse($registry->supportsProviderSelection('codex_cli', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'high'));
        app(ProviderCredentialStore::class)->replace('codex_cli', '{"test":"rotated"}');
        self::assertNotSame($this->generation, app(CredentialRevisionRegistry::class)->revision('codex_cli'));
        self::assertFalse($registry->get('codex-gpt-5.6-terra')->capabilityStatus->selectable());
        self::assertFalse($registry->supportsProviderSelection('codex_cli', AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'medium'));
        self::assertSame('fake', $registry->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium')->profile->id);
    }

    public function test_static_available_and_missing_human_evidence_never_supply_readiness(): void
    {
        $profiles = config('ai6.agent_profiles');
        self::assertIsArray($profiles);
        $profiles['codex-gpt-5.6-terra']['capability_status'] = 'available';
        config(['ai6.agent_profiles' => $profiles, 'ai6.codex.sandbox_proof' => '']);
        $this->publish();
        $registry = AgentProfileRegistry::fromConfiguredValues(app(StrictEnumParser::class));
        self::assertSame(CapabilityStatus::UNAVAILABLE, $registry->get('codex-gpt-5.6-terra')->capabilityStatus);
        self::assertSame('degraded', app(ProviderCapabilityReport::class)->diagnosis($registry->get('codex-gpt-5.6-terra'))['status']);
        unlink($this->root.'/reports/codex_cli.json');
        self::assertSame(CapabilityStatus::UNAVAILABLE, $registry->get('codex-gpt-5.6-terra')->capabilityStatus);
    }

    /** @return list<array{string}> */
    public static function corruptions(): array
    {
        return array_map(static fn (string $name): array => [$name], ['missing', 'oversize', 'utf8', 'schema', 'field', 'alias', 'boot', 'future', 'expired', 'duplicate', 'role', 'version', 'binding', 'binary_hash', 'runtime_hash']);
    }

    #[DataProvider('corruptions')]
    public function test_untrusted_report_corruptions_fail_closed(string $corruption): void
    {
        $path = $this->root.'/reports/codex_cli.json';
        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        switch ($corruption) {
            case 'missing': unlink($path);
                break;
            case 'oversize': file_put_contents($path, str_repeat('a', 65537));
                break;
            case 'utf8': file_put_contents($path, "\xff");
                break;
            default:
                match ($corruption) {
                    'schema' => $document['schema'] = 'foreign',
                    'field' => $document['extra'] = 'untrusted',
                    'alias' => $document['alias'] = 'grok_cli',
                    'boot' => $document['boot_id'] = bin2hex(random_bytes(16)),
                    'future' => $document['checked_at'] = time() + 600,
                    'expired' => $document['checked_at'] = time() - 600,
                    'duplicate' => $document['rows'][] = $document['rows'][0],
                    'role' => $document['rows'][0]['role'] = 'quality_review',
                    'version' => $document['rows'][0]['version'] = 'unbound',
                    'binding' => $document['rows'][0]['binding'] = str_repeat('0', 64),
                    'binary_hash' => $document['rows'][0]['binary_sha256'] = str_repeat('0', 64),
                    'runtime_hash' => $document['rows'][0]['runtime_profile_hash'] = str_repeat('0', 64),
                    default => throw new \LogicException('Unknown corruption fixture.'),
                };
                file_put_contents($path, json_encode($document, JSON_THROW_ON_ERROR));
        }
        $registry = app(AgentProfileRegistry::class);
        self::assertFalse($registry->get('codex-gpt-5.6-terra')->capabilityStatus->selectable());
        self::assertFalse(app(ProviderCapabilityReport::class)->doctor('codex_cli')->passed);
        self::assertTrue($registry->get('fake')->capabilityStatus->selectable());
    }

    public function test_actual_boot_change_and_missing_presence_revoke_a_well_formed_report(): void
    {
        file_put_contents($this->root.'/presence/boot-id', bin2hex(random_bytes(16)));
        self::assertNull(app(ProviderCapabilityReport::class)->read('codex_cli'));
        $this->expectException(CredentialProjectionException::class);
        app(CredentialRevisionRegistry::class)->revision('codex_cli');
    }

    public function test_presence_expiry_blocks_start_but_does_not_revoke_an_active_turn(): void
    {
        file_put_contents($this->root.'/presence/heartbeat.json', json_encode(['boot_id' => $this->boot, 'recorded_at' => time() - 3600], JSON_THROW_ON_ERROR));
        $reports = app(ProviderCapabilityReport::class);
        self::assertNull($reports->read('codex_cli'));
        self::assertSame($this->generation, $reports->generation('codex_cli', false));
        $this->expectException(CredentialProjectionException::class);
        $reports->generation('codex_cli');
    }

    public function test_wrong_role_and_invalid_credentials_leave_store_and_generation_unchanged(): void
    {
        config(['ai6.runtime_role' => 'worker']);
        try {
            app(ProviderCredentialStore::class)->replace('codex_cli', '{"test":"forbidden"}');
            self::fail('A worker changed the provider store.');
        } catch (CredentialProjectionException) {
            self::assertSame($this->generation, app(CredentialRevisionRegistry::class)->revision('codex_cli'));
            self::assertSame('{"test":"synthetic"}', file_get_contents($this->root.'/store/codex_cli/auth.json'));
        }
    }

    private function publish(): void
    {
        $profile = app(AgentProfileRegistry::class)->get('codex-gpt-5.6-terra');
        $row = ['profile' => $profile->id, 'role' => 'implementation', 'model' => 'gpt-5.3-codex', 'effort' => 'medium',
            'status' => 'ready', 'reason' => 'ready', 'version' => '0.129.0-alpha.15',
            ...app(ProviderCapabilityReport::class)->bindings($profile, AgentRole::IMPLEMENTATION, 'gpt-5.3-codex', 'medium')];
        app(ProviderCredentialStore::class)->locked(fn () => app(ProviderCredentialStore::class)->publish('codex_cli', $this->generation, [$row], time(), $this->boot));
    }
}
