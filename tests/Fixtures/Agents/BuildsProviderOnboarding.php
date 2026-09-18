<?php

namespace Tests\Fixtures\Agents;

use App\AI6\Agents\AgentProfileRegistry;
use App\AI6\Agents\ExecutionHome;
use App\AI6\Agents\ExecutionHomeManager;
use App\AI6\Agents\ProviderCapabilityPublisher;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Agents\ProviderOnboarding;
use App\AI6\Agents\ProviderRuntimeProfileRegistry;
use App\AI6\Shared\Doctor\CodexCliDoctorCheck;
use App\AI6\Shared\Doctor\DoctorCheckResult;
use App\AI6\Shared\Doctor\GitHubCopilotCliDoctorCheck;
use App\AI6\Shared\Doctor\GrokCliDoctorCheck;
use App\AI6\Shared\Process\ControlProcessRunner;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\After;

trait BuildsProviderOnboarding
{
    private string $onboardingRoot;

    private string $nativeMailboxRoot;

    protected function usesNativeProviderMailbox(): bool
    {
        return false;
    }

    private function createOnboardingFixture(): void
    {
        $this->onboardingRoot = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/ai6-onboarding-'.bin2hex(random_bytes(8));
        foreach (['store', 'reports', 'presence', 'private'] as $name) {
            self::assertTrue(mkdir($this->onboardingRoot.'/'.$name, 0700, true));
        }
        config(['ai6.runtime_role' => 'agent',
            'ai6.provider_onboarding.store_root' => $this->onboardingRoot.'/store',
            'ai6.provider_onboarding.report_root' => $this->onboardingRoot.'/reports',
            'ai6.provider_onboarding.presence_root' => $this->onboardingRoot.'/presence',
            'ai6.provider_onboarding.private_root' => $this->onboardingRoot.'/private',
            'ai6.process.policies.control.working_roots' => [...config('ai6.process.policies.control.working_roots'), $this->onboardingRoot],
        ]);
        $boot = bin2hex(random_bytes(16));
        file_put_contents($this->onboardingRoot.'/presence/boot-id', $boot);
        app(ProviderCapabilityPublisher::class)->pulse($boot);
        $this->app->forgetInstance(ProcessPolicyRegistry::class);
        $this->app->forgetInstance(ControlProcessRunner::class);
    }

    #[After]
    protected function destroyOnboardingFixture(): void
    {
        if (isset($this->nativeMailboxRoot)) {
            (new Filesystem)->deleteDirectory($this->nativeMailboxRoot);
            unset($this->nativeMailboxRoot);
        }
        if (isset($this->onboardingRoot)) {
            (new Filesystem)->deleteDirectory($this->onboardingRoot);
            unset($this->onboardingRoot);
        }
    }

    /** Synthetic supervisor evidence for orchestration tests; never a native capability claim. */
    protected function seedProviderReports(): void
    {
        $role = config('ai6.runtime_role');
        if (! isset($this->onboardingRoot)) {
            $this->createOnboardingFixture();
        }
        if ($this->usesNativeProviderMailbox()) {
            if (PHP_OS_FAMILY !== 'Linux') {
                self::markTestSkipped('Native provider mailbox fixtures require Linux namespaces and tmpfs.');
            }
            // /dev/shm is an actual noexec/nosuid/nodev tmpfs in the Linux test
            // container. Keep executable Git/CLI fixtures on the separate /work mount.
            if (! isset($this->nativeMailboxRoot)) {
                $this->nativeMailboxRoot = '/dev/shm/ai6-native-mailbox-'.bin2hex(random_bytes(8));
                foreach (['inputs', 'outputs'] as $name) {
                    self::assertTrue(mkdir($this->nativeMailboxRoot.'/'.$name, 0700, true));
                }
            }
            config(['ai6.execution_mailboxes.agent_root' => $this->nativeMailboxRoot.'/inputs',
                'ai6.execution_mailboxes.agent_output_root' => $this->nativeMailboxRoot.'/outputs',
                'ai6.process.policies.agent.working_roots' => [$this->nativeMailboxRoot.'/inputs', $this->nativeMailboxRoot.'/outputs']]);
            $this->app->forgetInstance(ProcessPolicyRegistry::class);
            $this->app->forgetInstance(ControlProcessRunner::class);
        }
        config(['ai6.runtime_role' => 'agent']);
        file_put_contents($this->onboardingRoot.'/presence/boot-id', str_repeat('a', 32));
        app(ProviderCapabilityPublisher::class)->pulse(str_repeat('a', 32));
        try {
            foreach (['codex_cli' => 'codex', 'grok_cli' => 'grok', 'github_copilot_cli' => 'copilot'] as $alias => $key) {
                $binary = config('ai6.'.$key.'.binary');
                if (! is_string($binary) || ! is_file($binary)
                    || in_array($binary, ['/usr/local/bin/codex', '/usr/local/bin/grok', '/usr/local/bin/copilot'], true)) {
                    $binary = $this->onboardingRoot.'/fixture-'.$key;
                    file_put_contents($binary, "#!/bin/sh\nexit 91\n");
                    chmod($binary, 0755);
                    config(['ai6.'.$key.'.binary' => $binary]);
                }
                if ($alias === 'codex_cli') {
                    config(['ai6.codex.sandbox_proof' => FakeCodexBinary::sandboxProof()]);
                }
                $configuration = ProviderOnboarding::configuration($alias);
                $profiles = array_filter(app(AgentProfileRegistry::class)->configured(), static fn ($profile): bool => $profile->providerProfileAlias === $alias && $profile->capabilityStatus->selectable());
                if ($alias !== 'codex_cli') {
                    $evidence = [];
                    foreach ($profiles as $profile) {
                        foreach ($profile->roles as $candidateRole) {
                            foreach ($profile->models as $model) {
                                foreach ($profile->efforts as $effort) {
                                    $evidence[] = $configuration->evidenceKey(app(ProviderRuntimeProfileRegistry::class)->get($profile->runtimeProfileId), $candidateRole, $model, $effort);
                                }
                            }
                        }
                    }
                    config(['ai6.'.$key.'.capability_evidence' => $evidence]);
                }
                $store = app(ProviderCredentialStore::class);
                if (! is_file($this->onboardingRoot.'/store/'.$alias.'/generation')) {
                    $store->replace($alias, $alias === 'codex_cli' ? '{"OPENAI_API_KEY":"test-projection"}' : 'test-projection');
                }
                $rows = [];
                foreach ($profiles as $profile) {
                    foreach ($profile->roles as $candidateRole) {
                        foreach ($profile->models as $model) {
                            foreach ($profile->efforts as $effort) {
                                $rows[] = ['profile' => $profile->id, 'role' => $candidateRole->value, 'model' => $model, 'effort' => $effort,
                                    'status' => 'ready', 'reason' => 'ready', 'version' => $configuration->pinnedVersion,
                                    ...app(ProviderCapabilityReport::class)->bindings($profile, $candidateRole, $model, $effort)];
                            }
                        }
                    }
                }
                $store->locked(fn () => $store->publish($alias, $store->generation($alias), $rows, time(), app(ProviderCapabilityReport::class)->boot()));
            }
        } finally {
            config(['ai6.runtime_role' => $role]);
        }
    }

    /** Exercise the native producer separately from the public Doctor reader. */
    private function probeProvider(CodexCliDoctorCheck|GrokCliDoctorCheck|GitHubCopilotCliDoctorCheck $check): DoctorCheckResult
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Native provider probes require Linux namespaces.');
        }
        if ($check instanceof CodexCliDoctorCheck) {
            return app(ExecutionHomeManager::class)->withProbeHome('codex_cli', 'codex-cli-v1',
                fn (ExecutionHome $home): DoctorCheckResult => $check->probeCombination($home));
        }
        $alias = $check instanceof GrokCliDoctorCheck ? 'grok_cli' : 'github_copilot_cli';
        $details = [];
        $passed = true;
        foreach (app(AgentProfileRegistry::class)->configured() as $profile) {
            if ($profile->providerProfileAlias !== $alias) {
                continue;
            }
            foreach ($profile->roles as $role) {
                foreach ($profile->models as $model) {
                    foreach ($profile->efforts as $effort) {
                        $result = $check->probeCombination($profile->id, $role, $model, $effort);
                        $details = array_replace($details, $result->details);
                        $passed = $passed && $result->passed;
                    }
                }
            }
        }

        return new DoctorCheckResult($passed, $details);
    }
}
