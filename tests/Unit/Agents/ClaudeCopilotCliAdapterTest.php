<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentExecutionException;
use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\AgentProfileSelectionError;
use App\AI6\Agents\AgentProfileSelectionException;
use App\AI6\Agents\AgentRole;
use App\AI6\Agents\CapabilityStatus;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;

/** Run the complete transport, isolation, limit and failure matrix with the shipped Claude selection. */
final class ClaudeCopilotCliAdapterTest extends GitHubCopilotCliAdapterTest
{
    protected function copilotModel(): string
    {
        return 'claude-sonnet-4.6';
    }

    public function test_shipped_profile_is_complete_and_unchecked_and_foreign_approval_values_are_refused(): void
    {
        $registry = app(AgentProfileRegistry::class);
        $profile = $registry->get('copilot-claude-sonnet-review');
        self::assertSame('github_copilot_cli', $profile->providerProfileAlias);
        self::assertSame('github_copilot_cli', $profile->adapterId);
        self::assertSame('github-copilot-cli-v1', $profile->runtimeProfileId);
        self::assertSame(['claude-sonnet-4.6'], $profile->models);
        self::assertSame(['provider_default'], $profile->efforts);
        self::assertSame([AgentRole::QUALITY_REVIEW], $profile->roles);
        self::assertSame(CapabilityStatus::UNCHECKED, $profile->capabilityStatus);
        foreach ([['claude-sonnet-4.6', 'provider_default', AgentRole::QUALITY_REVIEW, AgentProfileSelectionError::CAPABILITY_NOT_AVAILABLE],
            ['claude-unconfigured', 'provider_default', AgentRole::QUALITY_REVIEW, AgentProfileSelectionError::COMBINATION_NOT_ALLOWED],
            ['claude-sonnet-4.6', 'high', AgentRole::QUALITY_REVIEW, AgentProfileSelectionError::COMBINATION_NOT_ALLOWED],
            ['claude-sonnet-4.6', 'provider_default', AgentRole::FINDING_VERIFICATION, AgentProfileSelectionError::COMBINATION_NOT_ALLOWED]] as [$model, $effort, $role, $reason]) {
            try {
                $registry->resolve($profile->id, $role, $model, $effort);
                self::fail('Unapproved selection resolved.');
            } catch (AgentProfileSelectionException $exception) {
                self::assertSame($reason, $exception->reason);
                self::assertStringNotContainsString($model, $exception->getMessage());
                self::assertStringNotContainsString($effort, $exception->getMessage());
            }
        }
        self::assertSame('fake', $registry->resolve('fake', AgentRole::QUALITY_REVIEW, 'fake-model', 'medium')->profile->adapterId);
        self::assertInstanceOf(GitHubCopilotCliAdapter::class, app()->makeWith(AgentAdapter::class, ['providerAlias' => $profile->providerProfileAlias]));
        foreach (['claude_cli', 'unknown'] as $alias) {
            try {
                app()->makeWith(AgentAdapter::class, ['providerAlias' => $alias]);
                self::fail('Foreign alias resolved.');
            } catch (AgentExecutionException $exception) {
                self::assertSame('agent_adapter_unavailable', $exception->reason);
            }
        }
    }

    public function test_evidence_cannot_authorize_an_unregistered_model_or_an_effort_override(): void
    {
        $adapter = $this->copilotAdapter();
        $runtime = app(ProviderRuntimeProfileRegistry::class)->get('github-copilot-cli-v1');
        foreach ([['claude-unconfigured', 'provider_default', 'agent_copilot_selection_unbound'],
            ['claude-sonnet-4.6', 'high', 'agent_copilot_selection_unsupported'],
            ['gpt-5.4', 'provider_default', 'agent_copilot_capability_unproven']] as [$model, $effort, $reason]) {
            try {
                $adapter->assertSelection($runtime, AgentRole::QUALITY_REVIEW, $model, $effort);
                self::fail('Unbound selection accepted.');
            } catch (AgentExecutionException $exception) {
                self::assertSame($reason, $exception->reason);
            }
            self::assertSame([], $adapter->lastCommand);
        }
    }

    public function test_claude_documentation_keeps_the_real_gate_and_copilot_only_smoke_explicit(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        self::assertIsString($readme);
        foreach (['copilot-claude-sonnet-review', 'AI6_COPILOT_SMOKE_MODEL=claude-sonnet-4.6',
            '--filter=test_real_linux_claude_model_uses_only_the_copilot_transport', 'neue Invocation ohne natives Resume',
            'AI6-034_MG-01_ABNAHMEPROTOKOLL.md'] as $required) {
            self::assertStringContainsString($required, $readme);
        }
        $protocol = file_get_contents(base_path('docs/AI6-034_MG-01_ABNAHMEPROTOKOLL.md'));
        self::assertIsString($protocol);
        self::assertStringContainsString('Ergebnisfreie Vorlage', $protocol);
        self::assertStringContainsString('kein Claude-CLI-Binary', $protocol);
        $configuration = file_get_contents(config_path('ai6.php'));
        self::assertIsString($configuration);
        foreach (['AI6_CLAUDE_', 'CLAUDE_HOME', "'claude_cli'"] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $configuration);
        }
    }
}
