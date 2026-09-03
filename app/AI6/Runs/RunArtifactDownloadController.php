<?php

namespace App\AI6\Runs;

use App\AI6\Projects\Models\Project;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;

/**
 * The authorized, read-only download of one stored run artifact.
 *
 * It hands out exactly the redacted bytes the store holds, as an attachment
 * without inline rendering, and refuses every artifact that belongs to another
 * run, exceeds its category size limit, expired or was removed by retention.
 * The route parameters are runId and artifactId, never model names: an
 * implicit binding would resolve before the policy middleware decides.
 */
final readonly class RunArtifactDownloadController
{
    public function __invoke(Project $project, string $runId, string $artifactId, RunArtifactStore $store, RetentionPolicy $retention): Response
    {
        Gate::authorize('viewRun', $project);
        $run = Run::query()->whereKey($runId)->where('project_id', $project->getKey())->firstOrFail();
        $artifact = RunArtifact::query()->whereKey($artifactId)->where('run_id', $run->getKey())->firstOrFail();

        if ($artifact->isDeleted() || $artifact->expires_at->lessThanOrEqualTo(Date::now())) {
            return $this->refused(410, 'Das Artefakt ist nach Ablauf seiner Aufbewahrung nicht mehr verfügbar.');
        }
        if ($retention->artifactLimit($artifact->kind)->exceeds($artifact->size_bytes)) {
            return $this->refused(413, 'Das Artefakt überschreitet das konfigurierte Größenlimit seiner Kategorie.');
        }
        $bytes = $store->bytes($artifact);
        if ($bytes === null || $retention->artifactLimit($artifact->kind)->exceeds(strlen($bytes))) {
            return $this->refused(410, 'Das Artefakt ist nicht mehr verfügbar.');
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="run-artifact-'.$artifact->sequence.'-'.$artifact->kind->value.'.txt"',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function refused(int $status, string $message): Response
    {
        return new Response($message, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
