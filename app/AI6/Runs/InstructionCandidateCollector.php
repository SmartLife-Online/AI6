<?php

namespace App\AI6\Runs;

use App\AI6\Agents\InstructionCandidate;
use App\AI6\Agents\InstructionCandidateOrigin;
use App\AI6\Agents\InstructionFileType;
use App\AI6\Agents\InstructionProfileRegistry;
use App\AI6\Git\ControlOperationTerminalConflict;
use App\AI6\Git\HardenedGitRunner;
use App\AI6\Git\ManagedProjectPath;
use App\AI6\Projects\Models\Project;
use App\AI6\Shared\Redaction\RedactionContext;

final readonly class InstructionCandidateCollector implements InstructionCandidateSource
{
    public function __construct(
        private InstructionProfileRegistry $profiles,
        private ManagedProjectPath $paths,
        private HardenedGitRunner $git,
    ) {}

    /**
     * @param  list<string>  $ticketFiles
     * @return list<InstructionCandidate>
     */
    public function collect(Project $project, string $providerProfile, array $ticketFiles, RedactionContext $context): array
    {
        if ($project->project_identifier === null || $project->control_oid === null) {
            throw new \InvalidArgumentException('Die Control-Bindung für die Instruktionsauflösung fehlt.');
        }
        $repositoryDirectory = $this->paths->repositoryDirectory($project->project_identifier);
        if (! is_dir($repositoryDirectory)) {
            throw new \InvalidArgumentException('Das verwaltete Repository für die Instruktionsauflösung fehlt.');
        }
        $repository = $this->paths->assertRepository($repositoryDirectory);
        $discoveries = $this->profiles->get($providerProfile)->discoveries;
        $paths = [];
        if (isset($discoveries['agents_md'])) {
            $paths['AGENTS.md'] = 'agents_md';
        }
        if (isset($discoveries['agents_md_nested'])) {
            foreach ($ticketFiles as $file) {
                $directory = str_contains($file, '/') ? dirname($file) : '.';
                while ($directory !== '.') {
                    $paths[str_replace('\\', '/', $directory).'/AGENTS.md'] = 'agents_md_nested';
                    $directory = dirname($directory);
                }
            }
        }
        ksort($paths, SORT_STRING);

        $candidates = [];
        foreach ($paths as $path => $discoveryName) {
            try {
                $blob = $this->git->readRegularBlob($repository, $project->control_oid, $path, $context);
            } catch (ControlOperationTerminalConflict $exception) {
                if ($exception->conflict === 'refresh_path_missing_or_ambiguous') {
                    continue;
                }
                throw $exception;
            }
            $candidates[] = new InstructionCandidate(
                $discoveryName,
                InstructionCandidateOrigin::REPOSITORY,
                true,
                InstructionFileType::REGULAR,
                $blob->relativePath,
                $blob->blobSha,
                $blob->content,
            );
        }

        return $candidates;
    }
}
