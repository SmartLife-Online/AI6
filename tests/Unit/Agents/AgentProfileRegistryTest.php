<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentProfileSelectionError;
use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\AgentSelection;
use App\AI6\Agents\CapabilityStatus;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Agents\RuntimeExtensionType;
use App\AI6\Git\CanonicalJson;
use App\AI6\Prompts\PromptRenderer;
use App\AI6\Prompts\PromptRenderRequest;
use App\AI6\Prompts\PromptVariables;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\StrictEnumParser;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;
use App\AI6\Shared\Redaction\RedactionContext;
use ReflectionMethod;
use Tests\TestCase;

final class AgentProfileRegistryTest extends TestCase
{
    public function test_configured_profiles_are_typed_deterministic_and_have_the_required_aliases(): void
    {
        $registry = $this->app->make(AgentProfileRegistry::class);
        $profiles = $registry->all();

        self::assertSame(['codex-gpt-5.6-terra', 'copilot-cli-review', 'fake', 'grok-cli-review'], array_column($profiles, 'id'));
        self::assertEqualsCanonicalizing(
            ['codex_cli', 'grok_cli', 'github_copilot_cli', 'fake'],
            array_column($profiles, 'providerProfileAlias'),
        );
        self::assertSame($profiles, $this->app->make(AgentProfileRegistry::class)->all());
        self::assertSame(CapabilityStatus::UNCHECKED, $registry->get('codex-gpt-5.6-terra')->capabilityStatus);
        self::assertFalse($registry->get('codex-gpt-5.6-terra')->capabilityStatus->selectable());
    }

    public function test_unknown_selection_values_and_cross_profile_combinations_fail_closed(): void
    {
        $registry = $this->app->make(AgentProfileRegistry::class);
        $cases = [
            ['unknown', AgentRole::IMPLEMENTATION, 'fake-model', 'medium', AgentProfileSelectionError::PROFILE_UNKNOWN],
            ['fake', AgentRole::IMPLEMENTATION, 'unknown-model', 'medium', AgentProfileSelectionError::COMBINATION_NOT_ALLOWED],
            ['fake', AgentRole::IMPLEMENTATION, 'fake-model', 'unknown-effort', AgentProfileSelectionError::COMBINATION_NOT_ALLOWED],
            ['grok-cli-review', AgentRole::IMPLEMENTATION, 'provider_default', 'provider_default', AgentProfileSelectionError::COMBINATION_NOT_ALLOWED],
            ['codex-gpt-5.6-terra', AgentRole::IMPLEMENTATION, 'gpt-5.6-terra', 'medium', AgentProfileSelectionError::CAPABILITY_NOT_AVAILABLE],
        ];

        foreach ($cases as [$profile, $role, $model, $effort, $reason]) {
            try {
                $registry->resolve($profile, $role, $model, $effort);
                self::fail('The invalid selection unexpectedly resolved.');
            } catch (AgentProfileSelectionException $exception) {
                self::assertSame($reason, $exception->reason);
                self::assertStringNotContainsString($model, $exception->getMessage());
                self::assertStringNotContainsString($effort, $exception->getMessage());
            }
        }
    }

    public function test_fake_resolves_every_role_without_external_effects(): void
    {
        $registry = $this->app->make(AgentProfileRegistry::class);

        foreach (AgentRole::cases() as $role) {
            $selection = $registry->resolve('fake', $role, 'fake-model', 'medium');
            self::assertSame('fake', $selection->profile->adapterId);
            self::assertSame($role, $selection->role);
        }

        $snapshot = $this->app->make(PromptRenderer::class)->snapshot([
            new PromptRenderRequest('implementation', new PromptVariables(['context' => 'Fake-Kontext'])),
            new PromptRenderRequest('quality_review', new PromptVariables(['context' => 'Fake-Review']), 'tests'),
            new PromptRenderRequest('finding_verification', new PromptVariables(['context' => 'Fake-Finding'])),
            new PromptRenderRequest('security_review', new PromptVariables(['context' => 'Fake-Security']), 'security'),
        ], new RedactionContext('project-test', null, 'fake-profile'));
        self::assertCount(4, $snapshot->renderedPrompts);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $snapshot->hash);
    }

    public function test_unknown_provider_alias_is_a_named_configuration_error_without_a_registry(): void
    {
        $configuration = config('ai6.agent_profiles');
        self::assertIsArray($configuration);
        $configuration['fake']['provider_profile'] = 'repository_defined_provider';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('ai6.agent_profiles.fake.provider_profile');
        AgentProfileRegistry::fromArray($configuration, new StrictEnumParser);
    }

    public function test_unavailable_is_not_selectable_and_role_and_flags_are_not_free_inputs(): void
    {
        $configuration = config('ai6.agent_profiles');
        self::assertIsArray($configuration);
        $configuration['fake']['capability_status'] = 'unavailable';
        $registry = AgentProfileRegistry::fromArray($configuration, new StrictEnumParser);

        try {
            $registry->resolve('fake', AgentRole::IMPLEMENTATION, 'fake-model', 'medium');
            self::fail('An unavailable profile unexpectedly resolved.');
        } catch (AgentProfileSelectionException $exception) {
            self::assertSame(AgentProfileSelectionError::CAPABILITY_NOT_AVAILABLE, $exception->reason);
        }

        $configuration['fake']['roles'][0] = 'unregistered_role';
        try {
            AgentProfileRegistry::fromArray($configuration, new StrictEnumParser);
            self::fail('An unregistered role unexpectedly produced a registry.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('ai6.agent_profiles.fake.roles', $exception->getMessage());
        }

        self::assertSame(
            ['profileId', 'role', 'model', 'effort'],
            array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                (new ReflectionMethod(AgentProfileRegistry::class, 'resolve'))->getParameters(),
            ),
        );
        self::assertFalse(property_exists(AgentSelection::class, 'adapterFlags'));
    }

    public function test_runtime_profiles_are_sealed_hashed_and_extensions_are_server_only(): void
    {
        $registry = $this->app->make(ProviderRuntimeProfileRegistry::class);
        $first = $registry->get('fake-v1');
        $second = $registry->get('fake-v1');
        self::assertSame($first->hash, $second->hash);
        foreach (RuntimeExtensionType::cases() as $type) {
            self::assertSame([], $first->extensions[$type->value]);
        }

        $configuration = config('ai6.provider_runtime_profiles');
        self::assertIsArray($configuration);
        $configuration['fake-v1']['version'] = 2;
        $configuration['fake-v1']['extensions']['skills'] = ['server_test_skill'];
        $changed = ProviderRuntimeProfileRegistry::fromArray(
            $configuration,
            new StrictPositiveIntegerParser,
            $this->app->make(CanonicalJson::class),
        )->get('fake-v1');

        self::assertTrue($changed->extensionEnabled(RuntimeExtensionType::SKILLS, 'server_test_skill'));
        self::assertNotSame($first->hash, $changed->hash);
        self::assertSame(2, $changed->version);

        foreach ([
            ['adapter_flags', 'server_flag', true],
            ['permissions', 'network', true],
        ] as [$section, $key, $value]) {
            $variant = $configuration;
            $variant['fake-v1']['version'] = 3;
            $variant['fake-v1'][$section][$key] = $value;
            $profile = ProviderRuntimeProfileRegistry::fromArray(
                $variant,
                new StrictPositiveIntegerParser,
                $this->app->make(CanonicalJson::class),
            )->get('fake-v1');
            self::assertNotSame($first->hash, $profile->hash);
            self::assertSame(3, $profile->version);
        }

        $root = dirname(__DIR__, 3).'/app/AI6/Agents/';
        $registrySource = file_get_contents($root.'ProviderRuntimeProfileRegistry.php');
        $profileSource = file_get_contents($root.'ProviderRuntimeProfile.php');
        self::assertIsString($registrySource);
        self::assertIsString($profileSource);
        preg_match_all('/\bconfig\(\s*[\'\"]([^\'\"]+)[\'\"]/', $registrySource.$profileSource, $configurationReads);
        self::assertSame(['ai6.provider_runtime_profiles'], $configurationReads[1]);
        foreach ([$registrySource, $profileSource] as $source) {
            foreach (['env(', 'getenv(', 'base_path(', 'storage_path(', 'file_get_contents(', 'fopen(', 'glob(', 'ProjectConfiguration', '$_ENV', '$_SERVER'] as $forbiddenSource) {
                self::assertStringNotContainsString($forbiddenSource, $source);
            }
        }
    }
}
