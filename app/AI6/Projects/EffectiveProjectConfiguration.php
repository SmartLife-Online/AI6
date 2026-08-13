<?php

namespace App\AI6\Projects;

use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectConfigDraft;
use App\AI6\Projects\Models\ProjectConfigSnapshot;
use App\AI6\Shared\Config\ConfigurationException;

final readonly class EffectiveProjectConfiguration
{
    private ProjectConfiguration $defaults;

    private string $defaultsHash;

    public function __construct(
        ProjectConfigurationParser $parser,
        ProjectConfigurationHasher $hasher,
    ) {
        $configured = config('ai6.project_config.server_defaults');
        $result = $parser->validate(is_array($configured) ? $configured : []);
        if (! $result->valid() || ! $result->configuration instanceof ProjectConfiguration) {
            throw new ConfigurationException('The trusted project configuration defaults are invalid.');
        }
        $this->defaults = $result->configuration;
        $this->defaultsHash = $hasher->hash($this->defaults);
    }

    public function for(Project $project): ProjectConfigurationBinding
    {
        $latestDraft = ProjectConfigDraft::query()
            ->where('project_id', $project->getKey())
            ->where('control_commit', $project->control_oid)
            ->where('control_generation', $project->control_generation)
            ->latest('created_at')
            ->latest('id')
            ->first();

        if ($latestDraft instanceof ProjectConfigDraft && $latestDraft->state === 'absent') {
            return $this->defaults($project);
        }

        $snapshot = ProjectConfigSnapshot::query()
            ->where('project_id', $project->getKey())
            ->where('control_generation', $project->control_generation)
            ->latest('approved_at')
            ->latest('id')
            ->first();

        return $snapshot instanceof ProjectConfigSnapshot
            ? $this->fromSnapshot($snapshot)
            : $this->defaults($project);
    }

    public function snapshot(string $snapshotId): ProjectConfigurationBinding
    {
        return $this->fromSnapshot(ProjectConfigSnapshot::query()->findOrFail($snapshotId));
    }

    private function defaults(Project $project): ProjectConfigurationBinding
    {
        return new ProjectConfigurationBinding(
            ProjectConfigurationSource::SERVER_DEFAULTS,
            $this->defaults,
            $this->defaultsHash,
            null,
            null,
            $project->control_generation,
        );
    }

    private function fromSnapshot(ProjectConfigSnapshot $snapshot): ProjectConfigurationBinding
    {
        return new ProjectConfigurationBinding(
            ProjectConfigurationSource::APPROVED_SNAPSHOT,
            new ProjectConfiguration($snapshot->normalized_config),
            $snapshot->config_hash,
            $snapshot->blob_sha,
            (string) $snapshot->getKey(),
            $snapshot->control_generation,
        );
    }
}
