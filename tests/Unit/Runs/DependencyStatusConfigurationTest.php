<?php

namespace Tests\Unit\Runs;

use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\ProjectConfigurationParser;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Tickets\DependencySatisfiedStatusAllowlist;
use Tests\Feature\Tickets\TicketUiTestCase;

final class DependencyStatusConfigurationTest extends TicketUiTestCase
{
    public function test_effective_configuration_accepts_only_server_allowlisted_dependency_statuses(): void
    {
        $defaults = config('ai6.project_config.server_defaults');
        self::assertIsArray($defaults);
        $defaults['dependency_satisfied_statuses'] = ['review'];
        config([
            'ai6.project_config.server_defaults' => $defaults,
            'ai6.project_config.dependency_satisfied_status_allowlist' => ['review', 'done'],
        ]);
        $this->forgetConfigurationBindings();
        self::assertInstanceOf(EffectiveProjectConfiguration::class, $this->app->make(EffectiveProjectConfiguration::class));

        config(['ai6.project_config.dependency_satisfied_status_allowlist' => ['done']]);
        $this->forgetConfigurationBindings();
        $this->expectException(ConfigurationException::class);
        $this->app->make(EffectiveProjectConfiguration::class);
    }

    private function forgetConfigurationBindings(): void
    {
        foreach ([DependencySatisfiedStatusAllowlist::class, ProjectConfigurationParser::class, EffectiveProjectConfiguration::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }
}
