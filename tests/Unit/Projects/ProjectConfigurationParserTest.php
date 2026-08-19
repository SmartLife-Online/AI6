<?php

namespace Tests\Unit\Projects;

use App\AI6\Agents\AgentInputLimits;
use App\AI6\Projects\ProjectConfiguration;
use App\AI6\Projects\ProjectConfigurationHasher;
use App\AI6\Projects\ProjectConfigurationParser;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Tickets\DependencySatisfiedStatusAllowlist;
use Tests\TestCase;

final class ProjectConfigurationParserTest extends TestCase
{
    public function test_strict_schema_accepts_plan_shape_and_hashes_semantics_not_yaml_syntax(): void
    {
        $parser = $this->app->make(ProjectConfigurationParser::class);
        $first = $parser->parse($this->validYaml());
        $second = $parser->parse($this->replace($this->validYaml(), "version: 1\ntickets_path: tickets", "tickets_path: tickets\nversion: 1"));

        self::assertTrue($first->valid());
        self::assertTrue($second->valid());
        self::assertInstanceOf(ProjectConfiguration::class, $first->configuration);
        self::assertInstanceOf(ProjectConfiguration::class, $second->configuration);
        self::assertSame('tickets', $first->configuration->ticketsPath());
        self::assertSame(
            $this->app->make(ProjectConfigurationHasher::class)->hash($first->configuration),
            $this->app->make(ProjectConfigurationHasher::class)->hash($second->configuration),
        );

        $secretLooking = $parser->parse($this->replace($this->validYaml(), 'tickets_path: tickets', 'tickets_path: credential-looking-name'));
        self::assertTrue($secretLooking->valid());
        self::assertSame('credential-looking-name', $secretLooking->configuration?->ticketsPath());
    }

    public function test_unsafe_keys_profiles_commands_limits_and_paths_fail_with_named_value_free_errors(): void
    {
        $parser = $this->app->make(ProjectConfigurationParser::class);
        $cases = [
            [$this->replace($this->validYaml(), 'push_mode: manual', "control_branch: refs/heads/evil\npush_mode: manual"), 'yaml_unknown_key', 'refs/heads/evil'],
            [$this->replace($this->validYaml(), 'push_mode: manual', "remote: ssh://host/secret\npush_mode: manual"), 'yaml_unknown_key', 'ssh://host/secret'],
            [$this->replace($this->validYaml(), 'push_mode: manual', "managed_path: /srv/private\npush_mode: manual"), 'yaml_unknown_key', '/srv/private'],
            [$this->replace($this->validYaml(), 'push_mode: manual', "security_profile: disabled\npush_mode: manual"), 'yaml_unknown_key', 'disabled'],
            [$this->replace($this->validYaml(), 'push_mode: manual', "api_token: raw-super-secret\npush_mode: manual"), 'yaml_unknown_key', 'raw-super-secret'],
            [$this->replace($this->validYaml(), 'push_mode: manual', "ticket_glob: tickets/**\npush_mode: manual"), 'yaml_unknown_key', 'tickets/**'],
            [$this->replace($this->validYaml(), 'php-targeted', 'php -r "system(1)"'), 'check_profile_unknown', 'system(1)'],
            [$this->replace($this->validYaml(), 'codex-gpt-5.6-terra', 'unknown-model'), 'model_profile_unknown', 'unknown-model'],
            [$this->replace($this->validYaml(), 'ticket_validation_profile: generic_v1', 'ticket_validation_profile: repository-defined'), 'enum_value_unknown', 'repository-defined'],
            [$this->replace($this->validYaml(), 'max_fix_rounds: 3', 'max_fix_rounds: 999'), 'positive_integer_invalid', '999'],
            [$this->replace($this->validYaml(), 'max_fix_rounds: 3', 'max_fix_rounds: 0'), 'positive_integer_invalid', '0'],
            [$this->replace($this->validYaml(), 'max_fix_rounds: 3', 'max_fix_rounds: many'), 'positive_integer_invalid', 'many'],
            [$this->replace($this->validYaml(), 'tickets_path: tickets', 'tickets_path: ../tickets'), 'tickets_path_invalid', '../tickets'],
            [$this->replace($this->validYaml(), 'app/**', '../**'), 'scope_glob_invalid', '../**'],
        ];

        foreach ($cases as [$yaml, $expected, $forbiddenValue]) {
            $result = $parser->parse($yaml);
            self::assertFalse($result->valid());
            self::assertContains($expected, array_map(static fn ($error): string => $error->code, $result->errors));
            self::assertStringNotContainsString($forbiddenValue, json_encode($result->errors, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Plan §8.2 revision V1.7.3: `scope.unlisted_paths` is the trusted project
     * default for a path outside `auto_allow` and outside every sensible
     * category. It is optional with the server default `auto_allow`, accepts
     * exactly two values, and rejects anything else.
     */
    public function test_unlisted_paths_accepts_both_trusted_values_and_defaults_to_auto_allow(): void
    {
        $parser = $this->app->make(ProjectConfigurationParser::class);

        $absent = $parser->parse($this->validYaml());
        self::assertTrue($absent->valid());
        self::assertSame('auto_allow', $absent->configuration?->unlistedPaths());

        $strict = $parser->parse($this->replace(
            $this->validYaml(),
            'scope:
  auto_allow:',
            'scope:
  unlisted_paths: require_approval
  auto_allow:',
        ));
        self::assertTrue($strict->valid());
        self::assertSame('require_approval', $strict->configuration?->unlistedPaths());

        $explicitDefault = $parser->parse($this->replace(
            $this->validYaml(),
            'scope:
  auto_allow:',
            'scope:
  unlisted_paths: auto_allow
  auto_allow:',
        ));
        self::assertTrue($explicitDefault->valid());
        self::assertSame('auto_allow', $explicitDefault->configuration?->unlistedPaths());

        // An unknown value is refused; project content never invents a track.
        $unknown = $parser->parse($this->replace(
            $this->validYaml(),
            'scope:
  auto_allow:',
            'scope:
  unlisted_paths: always_allow
  auto_allow:',
        ));
        self::assertFalse($unknown->valid());
        self::assertContains('enum_value_unknown', array_map(static fn ($error): string => $error->code, $unknown->errors));
    }

    public function test_ticket_paths_and_scope_globs_follow_the_closed_repository_relative_grammar(): void
    {
        $parser = $this->app->make(ProjectConfigurationParser::class);
        $nested = $parser->parse($this->replace($this->validYaml(), 'tickets_path: tickets', 'tickets_path: packages/product-tickets'));
        self::assertTrue($nested->valid());
        self::assertSame('packages/product-tickets', $nested->configuration?->ticketsPath());

        $cases = [
            ['tickets_path: tickets', 'tickets_path: /tickets', 'tickets_path_invalid'],
            ['tickets_path: tickets', 'tickets_path: C:/tickets', 'tickets_path_invalid'],
            ['tickets_path: tickets', 'tickets_path: tickets\\nested', 'tickets_path_invalid'],
            ['tickets_path: tickets', 'tickets_path: tickets//nested', 'tickets_path_invalid'],
            ['tickets_path: tickets', 'tickets_path: tickets/./nested', 'tickets_path_invalid'],
            ['tickets_path: tickets', 'tickets_path: tickets/../nested', 'tickets_path_invalid'],
            ['tickets_path: tickets', 'tickets_path: tickets/*', 'tickets_path_invalid'],
            ['tickets_path: tickets', 'tickets_path: "tickets\\u0000nested"', 'tickets_path_invalid'],
            ['app/**', '/app/**', 'scope_glob_invalid'],
            ['app/**', 'app/../**', 'scope_glob_invalid'],
            ['app/**', 'app\\**', 'scope_glob_invalid'],
        ];
        foreach ($cases as [$search, $replacement, $code]) {
            $result = $parser->parse($this->replace($this->validYaml(), $search, $replacement));
            self::assertFalse($result->valid(), $replacement);
            self::assertContains($code, array_map(static fn ($error): string => $error->code, $result->errors), $replacement);
        }
    }

    public function test_dependency_statuses_use_the_trusted_server_allowlist_while_done_remains_the_default(): void
    {
        self::assertSame(
            ['review', 'done'],
            config('ai6.project_config.dependency_satisfied_status_allowlist'),
        );
        self::assertSame(['done'], config('ai6.project_config.server_defaults.dependency_satisfied_statuses'));

        $parser = $this->app->make(ProjectConfigurationParser::class);
        $configured = $parser->parse($this->replace(
            $this->validYaml(),
            "  - done\n",
            "  - review\n  - done\n",
        ));
        self::assertTrue($configured->valid());
        self::assertSame(['review', 'done'], $configured->configuration?->values['dependency_satisfied_statuses']);

        $nonDelivering = $parser->parse($this->replace($this->validYaml(), '  - done', '  - cancelled'));
        self::assertFalse($nonDelivering->valid());
        self::assertContains('dependency_status_unknown', array_column($nonDelivering->errors, 'code'));

        $unknown = $parser->parse($this->replace($this->validYaml(), '  - done', '  - unknown'));
        self::assertFalse($unknown->valid());
        self::assertContains('dependency_status_unknown', array_column($unknown->errors, 'code'));

        $this->expectException(ConfigurationException::class);
        new DependencySatisfiedStatusAllowlist(['done', 'unknown']);
    }

    public function test_model_profile_names_efforts_and_role_combinations_come_from_the_agent_registry(): void
    {
        $parser = $this->app->make(ProjectConfigurationParser::class);
        $invalidImplementation = $parser->parse($this->replace(
            $this->validYaml(),
            'implementation_profile: codex-gpt-5.6-terra',
            'implementation_profile: grok-cli-review',
        ));
        self::assertFalse($invalidImplementation->valid());
        self::assertContains('model_profile_combination_invalid', array_column($invalidImplementation->errors, 'code'));

        $invalidReviewer = $parser->parse($this->replace(
            $this->validYaml(),
            'profile: grok-cli-review',
            'profile: codex-gpt-5.6-terra',
        ));
        self::assertFalse($invalidReviewer->valid());
        self::assertContains('model_profile_combination_invalid', array_column($invalidReviewer->errors, 'code'));

        $allowlist = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Agents/ModelProfileAllowlist.php');
        $projectParser = file_get_contents(dirname(__DIR__, 3).'/app/AI6/Projects/ProjectConfigurationParser.php');
        self::assertIsString($allowlist);
        self::assertIsString($projectParser);
        self::assertArrayNotHasKey('model_profiles', config('ai6.project_config'));
        self::assertArrayNotHasKey('efforts', config('ai6.project_config'));
        self::assertStringContainsString('AgentProfileRegistry', $allowlist);

        $root = dirname(__DIR__, 3).'/app/AI6';
        $allowedSelectionSources = [
            strtr($root.'/Agents/AgentProfileRegistry.php', '\\', '/'),
            strtr($root.'/Agents/ProviderRuntimeProfileRegistry.php', '\\', '/'),
        ];
        $agentProfileReaders = [];
        $runtimeProfileReaders = [];
        $secondarySelectionSources = [];
        self::assertSame(
            ['ai6.direct_models', 'ai6.facade_efforts', 'ai6.fluent_adapter_flags'],
            $this->configurationKeys(<<<'PHP'
            config('ai6.direct_models');
            Config::get('ai6.facade_efforts');
            config()->get('ai6.fluent_adapter_flags');
            PHP),
        );
        self::assertTrue($this->usesInjectedConfigurationRepository(
            'use Illuminate\Contracts\Config\Repository as ConfigRepository;',
        ));
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $path = strtr($file->getPathname(), '\\', '/');
            foreach ($this->configurationKeys($source) as $configurationKey) {
                if ($configurationKey === 'ai6.agent_profiles') {
                    $agentProfileReaders[] = $path;
                }
                if ($configurationKey === 'ai6.provider_runtime_profiles') {
                    $runtimeProfileReaders[] = $path;
                }
                if (preg_match('/(?:models?|efforts?|adapter[_-]?flags?)/i', $configurationKey) === 1
                    && ! in_array($path, $allowedSelectionSources, true)) {
                    $secondarySelectionSources[] = $path;
                }
            }
            if ((str_contains($source, 'config(') || str_contains($source, 'Config::'))
                && preg_match('/[\'\"](?:models|efforts|adapter_flags)[\'\"]/', $source) === 1
                && ! in_array($path, $allowedSelectionSources, true)) {
                $secondarySelectionSources[] = $path;
            }
            if ($this->usesInjectedConfigurationRepository($source)
                && ! in_array($path, $allowedSelectionSources, true)) {
                $secondarySelectionSources[] = $path;
            }
        }
        self::assertSame([$allowedSelectionSources[0]], $agentProfileReaders);
        self::assertSame([$allowedSelectionSources[1]], $runtimeProfileReaders);
        self::assertSame([], array_values(array_unique($secondarySelectionSources)));
    }

    public function test_project_configuration_cannot_raise_agent_input_limits(): void
    {
        $limits = $this->app->make(AgentInputLimits::class);
        $yaml = $this->validYaml()."\nagent_input_limits:\n  max_instruction_files: ".($limits->maxInstructionFiles + 1)
            ."\n  max_prompt_input_bytes: ".($limits->maxPromptInputBytes + 1)."\n";

        $result = $this->app->make(ProjectConfigurationParser::class)->parse($yaml);

        self::assertFalse($result->valid());
        self::assertContains('yaml_unknown_key', array_column($result->errors, 'code'));
        self::assertSame($limits->maxInstructionFiles, config('ai6.agent_input_limits.max_instruction_files'));
        self::assertSame($limits->maxPromptInputBytes, config('ai6.agent_input_limits.max_prompt_input_bytes'));
    }

    public function test_project_config_has_one_repository_read_path_and_one_effective_default_resolver(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $root = $repositoryRoot.'/app/AI6';
        $configPathMentions = [];
        $serverDefaultReads = [];
        $uiManagedCloneReads = [];
        $configurationJsonEncoders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            $path = strtr($file->getPathname(), '\\', '/');
            if (str_contains($source, '.ai6/config.yaml')) {
                $configPathMentions[] = $path;
            }
            if (str_contains($source, "config('ai6.project_config.server_defaults')")) {
                $serverDefaultReads[] = $path;
            }
            if ((str_contains($path, '/Projects/Http/ProjectConfigurationController.php')
                || str_contains($path, '/Projects/Http/ProjectController.php')
                || str_contains($path, '/Tickets/Livewire/')
                || str_contains($path, '/Tickets/TicketMutationController.php'))
                && (str_contains($source, 'ManagedProjectPath') || str_contains($source, 'HardenedGitRunner'))) {
                $uiManagedCloneReads[] = $path;
            }
            if (str_contains($path, '/Projects/ProjectConfiguration') && str_contains($source, 'json_encode(')) {
                $configurationJsonEncoders[] = $path;
            }
        }

        self::assertSame([
            strtr($root.'/Projects/Actions/QueueProjectConfigRefresh.php', '\\', '/'),
        ], $configPathMentions);
        self::assertSame([
            strtr($root.'/Projects/EffectiveProjectConfiguration.php', '\\', '/'),
        ], $serverDefaultReads);
        self::assertSame([], $uiManagedCloneReads);
        self::assertSame([], $configurationJsonEncoders);

        $hasher = file_get_contents($root.'/Projects/ProjectConfigurationHasher.php');
        $controlConfiguration = file_get_contents($root.'/Git/ControlOperationConfiguration.php');
        $serverConfiguration = file_get_contents($repositoryRoot.'/config/ai6.php');
        $reproject = file_get_contents($root.'/Tickets/Console/ReprojectUnparsedTicketsCommand.php');
        self::assertIsString($hasher);
        self::assertIsString($controlConfiguration);
        self::assertIsString($serverConfiguration);
        self::assertIsString($reproject);
        self::assertStringContainsString('CanonicalJson', $hasher);
        self::assertStringNotContainsString('json_encode(', $hasher);
        self::assertStringNotContainsString('refreshBasePath', $controlConfiguration);
        self::assertStringNotContainsString("'refresh_base_path' => env(", $serverConfiguration);
        self::assertStringContainsString('EffectiveProjectConfiguration', $reproject);
        self::assertStringNotContainsString('RefreshPathPolicy', $reproject);
        self::assertStringNotContainsString('TicketValidationConfiguration', $reproject);
    }

    private function validYaml(): string
    {
        return <<<'YAML'
        version: 1
        tickets_path: tickets
        ticket_validation_profile: generic_v1
        push_mode: manual
        auto_start_next: true
        dependency_satisfied_statuses:
          - done
        defaults:
          implementation_profile: codex-gpt-5.6-terra
          implementation_effort: medium
          reviewers:
            - profile: grok-cli-review
              effort: provider_default
        limits:
          max_fix_rounds: 3
          max_review_rounds: 4
          max_verification_rounds: 2
          max_agent_invocations: 20
          max_added_scope_paths: 12
          max_changed_files: 40
          max_changed_bytes: 2000000
          max_artifacts: 20
          max_artifact_bytes: 5000000
          max_total_artifact_bytes: 20000000
          max_provider_output_bytes: 2000000
          max_run_minutes: 180
        scope:
          auto_allow:
            - app/**
          require_approval:
            - .ai6/**
        checks:
          before_review:
            - php-targeted
          final:
            - php-all
            - git-diff-check
        YAML;
    }

    private function replace(string $input, string $search, string $replacement): string
    {
        $position = strpos($input, $search);

        if ($position === false) {
            return $input;
        }

        return substr($input, 0, $position).$replacement.substr($input, $position + strlen($search));
    }

    /** @return list<string> */
    private function configurationKeys(string $source): array
    {
        $keys = [];
        foreach ([
            '/\bconfig\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/',
            '/\bConfig\s*::\s*get\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/',
            '/\bconfig\s*\(\s*\)\s*->\s*get\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/',
        ] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        return array_values(array_unique($keys));
    }

    private function usesInjectedConfigurationRepository(string $source): bool
    {
        return str_contains($source, 'Illuminate\Contracts\Config\Repository');
    }
}
