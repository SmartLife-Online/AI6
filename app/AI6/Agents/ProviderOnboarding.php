<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Process\ExecutionRole;

/** Trusted paths and transport selection for AGT-007, not another profile catalog. */
final class ProviderOnboarding
{
    public static function assertAgent(): void
    {
        if (config('ai6.runtime_role') !== ExecutionRole::AGENT->value) {
            throw new CredentialProjectionException('Provider onboarding requires the agent supervisor.');
        }
    }

    public static function configuration(string $alias): CodexCliConfiguration|GrokCliConfiguration|GitHubCopilotCliConfiguration
    {
        return match ($alias) {
            'codex_cli' => CodexCliConfiguration::fromConfiguredValues(),
            'grok_cli' => GrokCliConfiguration::fromConfiguredValues(),
            'github_copilot_cli' => GitHubCopilotCliConfiguration::fromConfiguredValues(),
            default => throw new CredentialProjectionException('The provider alias is invalid.'),
        };
    }

    public static function filename(string $alias): string
    {
        return match ($alias) {
            'codex_cli' => 'auth.json',
            'grok_cli', 'github_copilot_cli' => 'token',
            default => throw new CredentialProjectionException('The provider alias is invalid.'),
        };
    }

    public static function path(string $key): string
    {
        $value = config('ai6.provider_onboarding.'.$key);
        if (! is_string($value) || $value === '' || str_contains($value, "\0")
            || preg_match('~\A(?:/|[A-Za-z]:[/\\\\])~D', $value) !== 1
            || array_intersect(explode('/', str_replace('\\', '/', $value)), ['.', '..']) !== []) {
            throw new ConfigurationException('The provider onboarding path is invalid.');
        }

        $path = rtrim(str_replace('\\', '/', $value), '/');
        if ((file_exists($path) || is_link($path)) && (! is_dir($path) || is_link($path)
            || str_replace('\\', '/', (string) realpath($path)) !== $path)) {
            throw new ConfigurationException('The provider onboarding path is not canonical.');
        }
        $otherPaths = [str_replace('\\', '/', base_path()), str_replace('\\', '/', storage_path()),
            config('ai6.execution_mailboxes.agent_root'), config('ai6.execution_mailboxes.agent_output_root')];
        foreach (['store_root', 'report_root', 'presence_root', 'private_root'] as $otherKey) {
            if ($otherKey !== $key) {
                $otherPaths[] = config('ai6.provider_onboarding.'.$otherKey);
            }
        }
        foreach ($otherPaths as $other) {
            if (! is_string($other) || $other === '') {
                throw new ConfigurationException('The provider onboarding path boundary is unavailable.');
            }
            $other = rtrim(str_replace('\\', '/', $other), '/');
            if ($path === $other || str_starts_with($path, $other.'/') || str_starts_with($other, $path.'/')) {
                throw new ConfigurationException('The provider onboarding paths overlap another authority.');
            }
        }

        return $path;
    }

    public static function seconds(string $key): int
    {
        $value = config('ai6.provider_onboarding.'.$key);
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[1-9][0-9]{0,4}\z/D', (string) $value) !== 1) {
            throw new ConfigurationException('The provider onboarding interval is invalid.');
        }

        return (int) $value;
    }
}
