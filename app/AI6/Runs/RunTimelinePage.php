<?php

namespace App\AI6\Runs;

use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\ExecutionJob;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunAgent;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The read-only base run page.
 *
 * It reads persisted redacted state only. The route parameter is deliberately
 * named runId and not run: a parameter that matches a public model property
 * would be resolved by Livewire's implicit binding before the policy middleware
 * decides, which turns authorization into a lookup result.
 */
#[Layout('layouts.app', ['title' => 'Run-Timeline – AI6'])]
final class RunTimelinePage extends Component
{
    #[Locked]
    public Project $project;

    #[Locked]
    public string $runId = '';

    public function mount(Project $project, string $runId): void
    {
        Gate::authorize('viewRun', $project);
        $this->project = $project;
        $this->runId = $this->run($project, $runId)->id;
    }

    public function render(): View
    {
        Gate::authorize('viewRun', $this->project);
        $run = $this->run($this->project, $this->runId);

        $summary = $this->latestImplementationSummary((string) $run->getKey());
        $payload = $summary === null ? [] : $this->readArtifactPayload($summary);

        return view('runs.timeline', [
            'run' => $run,
            'jobs' => ExecutionJob::query()->where('run_id', $run->getKey())
                ->orderBy('step_number')->orderBy('id')->get(),
            'sessions' => RunAgent::query()->where('run_id', $run->getKey())->orderBy('id')->get(),
            'events' => RunEvent::query()->where('run_id', $run->getKey())->orderBy('id')->get(),
            'changedFiles' => is_array($payload['changed_files'] ?? null) ? $payload['changed_files'] : [],
            'decisions' => is_array($payload['decisions'] ?? null) ? $payload['decisions'] : [],
        ]);
    }

    private function run(Project $project, string $runId): Run
    {
        return Run::query()->whereKey($runId)
            ->where('project_id', $project->getKey())
            ->firstOrFail();
    }

    private function latestImplementationSummary(string $runId): ?RunArtifact
    {
        /** @var RunArtifact|null $artifact */
        $artifact = RunArtifact::query()->where('run_id', $runId)
            ->where('kind', RunArtifactKind::IMPLEMENTATION_SUMMARY->value)
            ->orderByDesc('created_at')
            ->orderByDesc('sequence')
            ->first();

        return $artifact;
    }

    /** @return array<string, mixed> */
    private function readArtifactPayload(RunArtifact $artifact): array
    {
        $root = config('ai6.run_artifacts.root');
        if (! is_string($root) || $root === '') {
            return $artifact->redacted_metadata;
        }
        $path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $artifact->storage_reference);
        if (! is_file($path) || is_link($path)) {
            return [];
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            return [];
        }
        try {
            $decoded = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
