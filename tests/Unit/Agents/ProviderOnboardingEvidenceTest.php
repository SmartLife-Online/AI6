<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\GitHubCopilotCliConfiguration;
use App\AI6\Agents\ProviderBinaryDigest;
use App\AI6\Agents\ProviderCapabilityPublisher;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Reviews\VerifierCandidatePoolFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Agents\BuildsProviderOnboarding;
use Tests\TestCase;

final class ProviderOnboardingEvidenceTest extends TestCase
{
    use BuildsProviderOnboarding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOnboardingFixture();
        $profiles = config('ai6.agent_profiles');
        self::assertIsArray($profiles);
        foreach (['codex-gpt-5.6-terra', 'grok-cli-review', 'copilot-cli-review', 'copilot-claude-sonnet-review'] as $id) {
            $profiles[$id]['capability_status'] = 'available';
        }
        config(['ai6.agent_profiles' => $profiles]);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $this->seedProviderReports();
    }

    public function test_copilot_model_evidence_is_independent_but_logout_is_shared(): void
    {
        $registry = app(AgentProfileRegistry::class);
        self::assertTrue($registry->get('copilot-cli-review')->capabilityStatus->selectable());
        self::assertTrue($registry->get('copilot-claude-sonnet-review')->capabilityStatus->selectable());
        $configuration = GitHubCopilotCliConfiguration::fromConfiguredValues();
        $runtime = app(ProviderRuntimeProfileRegistry::class)->get($registry->get('copilot-cli-review')->runtimeProfileId);
        config(['ai6.copilot.capability_evidence' => [$configuration->evidenceKey($runtime, AgentRole::QUALITY_REVIEW, 'gpt-5.4', 'provider_default')]]);
        self::assertTrue($registry->supportsProviderSelection('github_copilot_cli', AgentRole::QUALITY_REVIEW, 'gpt-5.4', 'provider_default'));
        self::assertFalse($registry->supportsProviderSelection('github_copilot_cli', AgentRole::QUALITY_REVIEW, 'claude-sonnet-4.6', 'provider_default'));
        $doctor = app(ProviderCapabilityReport::class)->doctor('github_copilot_cli');
        self::assertTrue($doctor->passed);
        self::assertStringContainsString('unavailable', $doctor->details['copilot-claude-sonnet-review']);
        $foreign = file_get_contents($this->onboardingRoot.'/reports/codex_cli.json');
        app(ProviderCredentialStore::class)->replace('github_copilot_cli', null);
        self::assertFalse($registry->get('copilot-cli-review')->capabilityStatus->selectable());
        self::assertFalse($registry->get('copilot-claude-sonnet-review')->capabilityStatus->selectable());
        self::assertSame($foreign, file_get_contents($this->onboardingRoot.'/reports/codex_cli.json'));
        self::assertTrue($registry->get('fake')->capabilityStatus->selectable());
    }

    public function test_optional_claude_models_do_not_depend_on_the_profile_name(): void
    {
        $profiles = config('ai6.agent_profiles');
        $profiles['optional-reviewer'] = $profiles['copilot-claude-sonnet-review'];
        unset($profiles['copilot-claude-sonnet-review']);
        config(['ai6.agent_profiles' => $profiles]);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        $reports = app(ProviderCapabilityReport::class);
        // No report row exists for the renamed optional profile.
        $doctor = $reports->doctor('github_copilot_cli');
        self::assertTrue($doctor->passed);
        self::assertStringContainsString('unavailable', $doctor->details['optional-reviewer']);
        $profiles['optional-reviewer']['models'][] = 'gpt-5.4';
        config(['ai6.agent_profiles' => $profiles]);
        $this->app->forgetInstance(AgentProfileRegistry::class);
        self::assertFalse($reports->doctor('github_copilot_cli')->passed, 'Mixed profiles still require evidence for their mandatory models.');
    }

    public function test_registry_fingerprint_ignores_freshness_metadata_but_detects_expiry(): void
    {
        $registry = app(AgentProfileRegistry::class);
        $before = serialize($registry);
        $reports = app(ProviderCapabilityReport::class);
        $store = app(ProviderCredentialStore::class);
        $boot = bin2hex(random_bytes(16));
        foreach (['codex_cli', 'grok_cli', 'github_copilot_cli'] as $alias) {
            $document = $reports->read($alias);
            self::assertNotNull($document);
            $store->locked(fn () => $store->publish($alias, $document['generation'], $document['rows'], $document['checked_at'] - 1, $boot));
        }
        file_put_contents($this->onboardingRoot.'/presence/boot-id', $boot);
        app(ProviderCapabilityPublisher::class)->pulse($boot);
        self::assertSame($before, serialize($registry));
        $document = $reports->read('github_copilot_cli');
        self::assertNotNull($document);
        $store->locked(fn () => $store->publish('github_copilot_cli', $document['generation'], $document['rows'], time() - 301, $boot));
        self::assertNotSame($before, serialize($registry), 'Actual loss of readiness must still schedule reevaluation.');
    }

    /** @return list<array{string}> */
    public static function drift(): array
    {
        return [['pin'], ['binary'], ['runtime'], ['human']];
    }

    #[DataProvider('drift')]
    public function test_redating_old_evidence_does_not_repair_binding_drift(string $change): void
    {
        $registry = app(AgentProfileRegistry::class);
        self::assertTrue($registry->get('copilot-cli-review')->capabilityStatus->selectable());
        match ($change) {
            'pin' => config(['ai6.copilot.pinned_version' => 'unverified-test-version']),
            'binary' => file_put_contents($this->onboardingRoot.'/fixture-copilot', "#!/bin/sh\n# changed binary\nexit 92\n"),
            'runtime' => config(['ai6.provider_runtime_profiles.'.$registry->get('copilot-cli-review')->runtimeProfileId.'.version' => 2]),
            'human' => config(['ai6.copilot.capability_evidence' => []]),
            default => throw new \LogicException('Unknown drift fixture.'),
        };
        if ($change === 'runtime') {
            $this->app->forgetInstance(ProviderRuntimeProfileRegistry::class);
        }
        $reports = app(ProviderCapabilityReport::class);
        $document = $reports->read('github_copilot_cli');
        self::assertNotNull($document);
        $store = app(ProviderCredentialStore::class);
        $store->locked(fn () => $store->publish('github_copilot_cli', $document['generation'], $document['rows'], time(), $document['boot_id']));
        self::assertFalse($registry->get('copilot-cli-review')->capabilityStatus->selectable());
        self::assertFalse($registry->supportsProviderSelection('github_copilot_cli', AgentRole::QUALITY_REVIEW, 'gpt-5.4', 'provider_default'));
    }

    public function test_interrupted_mutation_cannot_be_released_by_a_late_probe(): void
    {
        $store = app(ProviderCredentialStore::class);
        $old = $store->generation('github_copilot_cli');
        $reports = app(ProviderCapabilityReport::class);
        $revoked = bin2hex(random_bytes(16));
        // Exact durable prefix of replace(): revocation reached disk, but the
        // store switch did not. The old credential and generation still exist.
        $store->locked(fn () => $store->publish('github_copilot_cli', $revoked, [], time(), $reports->boot()));
        $before = file_get_contents($this->onboardingRoot.'/reports/github_copilot_cli.json');
        app(ProviderCapabilityPublisher::class)->recheck('github_copilot_cli');
        self::assertSame($old, $store->generation('github_copilot_cli'));
        self::assertSame($before, file_get_contents($this->onboardingRoot.'/reports/github_copilot_cli.json'));
        self::assertFalse(app(AgentProfileRegistry::class)->get('copilot-cli-review')->capabilityStatus->selectable());
    }

    public function test_a_fresh_heartbeat_never_extends_the_probe_deadline(): void
    {
        $reports = app(ProviderCapabilityReport::class);
        $document = $reports->read('github_copilot_cli');
        self::assertNotNull($document);
        $store = app(ProviderCredentialStore::class);
        $store->locked(fn () => $store->publish('github_copilot_cli', $document['generation'], $document['rows'], time() - 301, $document['boot_id']));
        app(ProviderCapabilityPublisher::class)->pulse($document['boot_id']);
        self::assertNull($reports->read('github_copilot_cli'));
        self::assertFalse(app(AgentProfileRegistry::class)->get('copilot-cli-review')->capabilityStatus->selectable());
        self::assertSame($document['generation'], $reports->generation('github_copilot_cli', false));
        $store->replace('github_copilot_cli', null);
        self::assertNotSame($document['generation'], $reports->generation('github_copilot_cli', false));
    }

    /** @return list<array{int, int}> */
    public static function dueProbeAges(): array
    {
        return [[0, 241], [100, 201], [301, 0]];
    }

    #[DataProvider('dueProbeAges')]
    public function test_rechecks_follow_raw_report_age_and_reserve_the_previous_cycle_duration(int $duration, int $age): void
    {
        $reports = app(ProviderCapabilityReport::class);
        $store = app(ProviderCredentialStore::class);
        $publisher = app(ProviderCapabilityPublisher::class);
        $before = $reports->read('codex_cli');
        self::assertNotNull($before);
        // Missing binaries give deterministic turn-free probe results on every OS.
        foreach (['codex', 'grok', 'copilot'] as $key) {
            config(['ai6.'.$key.'.binary' => $this->onboardingRoot.'/absent']);
        }
        $publisher->due();
        self::assertSame($before, $reports->read('codex_cli'), 'A restarted publisher must respect the persisted probe time.');
        $foreign = $reports->read('grok_cli');
        $store->locked(fn () => $store->publish('codex_cli', $before['generation'], $before['rows'], time() - $age, $before['boot_id']));
        (new \ReflectionProperty($publisher, 'lastDuration'))->setValue($publisher, $duration);
        $publisher->due();
        $after = $reports->read('codex_cli');
        self::assertNotNull($after);
        self::assertSame($before['generation'], $after['generation']);
        self::assertSame('installation', $after['rows'][0]['reason']);
        self::assertGreaterThanOrEqual(time() - 5, $after['checked_at']);
        if ($duration < 300) {
            self::assertSame($foreign, $reports->read('grok_cli'), 'Fresh aliases do not inherit another alias\'s due state.');
        }
    }

    public function test_profile_inventory_reads_each_binary_only_once_and_invalidates_changed_metadata(): void
    {
        $reads = [];
        $this->app->instance(ProviderBinaryDigest::class, new ProviderBinaryDigest(
            static function (string $path) use (&$reads): string|false {
                $reads[$path] = ($reads[$path] ?? 0) + 1;

                return hash_file('sha256', $path);
            }));
        $registry = app(AgentProfileRegistry::class);
        $registry->all();
        self::assertCount(3, $reads);
        self::assertSame([1, 1, 1], array_values($reads));
        $registry->all();
        self::assertSame([1, 1, 1], array_values($reads));
        $binary = $this->onboardingRoot.'/fixture-copilot';
        file_put_contents($binary, "#!/bin/sh\n# replacement binary\nexit 92\n");
        self::assertFalse($registry->get('copilot-cli-review')->capabilityStatus->selectable());
        self::assertSame(2, $reads[$binary]);
    }

    public function test_quality_review_evidence_does_not_create_a_verifier_candidate(): void
    {
        $reports = app(ProviderCapabilityReport::class);
        $document = $reports->read('grok_cli');
        self::assertNotNull($document);
        $rows = array_values(array_filter($document['rows'], static fn (array $row): bool => $row['role'] === 'quality_review'));
        $store = app(ProviderCredentialStore::class);
        $store->locked(fn () => $store->publish('grok_cli', $document['generation'], $rows, time(), $document['boot_id']));
        self::assertTrue(app(AgentProfileRegistry::class)->get('grok-cli-review')->capabilityStatus->selectable());
        $candidates = app(VerifierCandidatePoolFactory::class)->all();
        self::assertNotContains('grok-cli-review', array_column($candidates, 'profileId'));
    }

    public function test_cleanup_releases_sealed_files_before_removing_private_projection(): void
    {
        $directory = $this->onboardingRoot.'/private/projection-'.bin2hex(random_bytes(16));
        mkdir($directory, 0700);
        file_put_contents($directory.'/token', 'synthetic');
        chmod($directory.'/token', 0400);
        chmod($directory, 0500);
        app(ProviderCredentialStore::class)->cleanup($directory);
        self::assertDirectoryDoesNotExist($directory);
    }
}
