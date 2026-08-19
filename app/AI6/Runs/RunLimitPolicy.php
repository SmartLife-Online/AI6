<?php

namespace App\AI6\Runs;

use App\AI6\Git\RunPatchChange;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;

/** The exclusive import-limit check. An exceedance has no partial effect. */
final readonly class RunLimitPolicy
{
    /**
     * @param  list<RunPatchChange>  $changes
     * @param  list<array{bytes: int}>  $artifacts
     */
    public function evaluate(Run $run, array $changes, array $artifacts, int $providerOutputBytes): ?ImportLimitResult
    {
        $limits = $this->effective($run);

        if (count($changes) > $limits['max_changed_files']) {
            return new ImportLimitResult(ImportLimit::MAX_CHANGED_FILES, count($changes), $limits['max_changed_files']);
        }
        $changedBytes = array_sum(array_map(static fn (RunPatchChange $change): int => $change->bytes, $changes));
        if ($changedBytes > $limits['max_changed_bytes']) {
            return new ImportLimitResult(ImportLimit::MAX_CHANGED_BYTES, $changedBytes, $limits['max_changed_bytes']);
        }
        if (count($artifacts) > $limits['max_artifacts']) {
            return new ImportLimitResult(ImportLimit::MAX_ARTIFACTS, count($artifacts), $limits['max_artifacts']);
        }
        foreach ($artifacts as $artifact) {
            if ($artifact['bytes'] > $limits['max_artifact_bytes']) {
                return new ImportLimitResult(ImportLimit::MAX_ARTIFACT_BYTES, $artifact['bytes'], $limits['max_artifact_bytes']);
            }
        }
        $totalArtifactBytes = array_sum(array_map(static fn (array $artifact): int => $artifact['bytes'], $artifacts));
        if ($totalArtifactBytes > $limits['max_total_artifact_bytes']) {
            return new ImportLimitResult(ImportLimit::MAX_TOTAL_ARTIFACT_BYTES, $totalArtifactBytes, $limits['max_total_artifact_bytes']);
        }
        if ($providerOutputBytes > $limits['max_provider_output_bytes']) {
            return new ImportLimitResult(ImportLimit::MAX_PROVIDER_OUTPUT_BYTES, $providerOutputBytes, $limits['max_provider_output_bytes']);
        }

        return null;
    }

    /** @return array<string, int> */
    public function effective(Run $run): array
    {
        $approved = ($run->agent_profile_snapshot ?? [])['limits'] ?? [];
        if (! is_array($approved)) {
            $approved = [];
        }
        $defaults = config('ai6.project_config.server_defaults.limits');
        $maxima = config('ai6.project_config.server_maxima');
        if (! is_array($defaults) || ! is_array($maxima)) {
            throw new ImplementationImportException('limit_configuration_missing', 'The approved import limits are unavailable.');
        }

        $effective = [];
        foreach (['max_changed_files', 'max_changed_bytes', 'max_artifacts', 'max_artifact_bytes', 'max_total_artifact_bytes', 'max_provider_output_bytes', 'max_added_scope_paths'] as $name) {
            $value = $approved[$name] ?? $defaults[$name] ?? null;
            $maximum = $maxima[$name] ?? null;
            if (! is_int($value) || ! is_int($maximum) || $value < 1) {
                throw new ImplementationImportException('limit_configuration_missing', 'The approved import limit '.$name.' is unavailable.');
            }
            $effective[$name] = min($value, $maximum);
        }

        $grant = $run->exists
            ? RunArtifact::query()->where('run_id', $run->getKey())
                ->where('kind', RunArtifactKind::LIMIT_GRANT->value)
                ->orderByDesc('created_at')
                ->orderByDesc('sequence')
                ->first()
            : null;
        if ($grant !== null) {
            foreach ($effective as $name => $value) {
                $raised = $grant->redacted_metadata[$name] ?? null;
                if (is_int($raised) && $raised > $value) {
                    $maximum = $maxima[$name] ?? $raised;
                    $effective[$name] = min($raised, is_int($maximum) ? $maximum : $raised);
                }
            }
        }

        return $effective;
    }

    public function serverMaximum(string $name): int
    {
        $maximum = config('ai6.project_config.server_maxima.'.$name);
        if (! is_int($maximum) || $maximum < 1) {
            throw new ImplementationImportException('limit_configuration_missing', 'The server maximum '.$name.' is unavailable.');
        }

        return $maximum;
    }

    /** @return array<string, int>|null */
    public function raiseToObserved(Run $run, ImportLimitResult $result): ?array
    {
        $name = $result->limit->value;
        $maximum = $this->serverMaximum($name);
        if ($result->observed > $maximum) {
            return null;
        }
        $effective = $this->effective($run);
        $effective[$name] = $result->observed;

        return $effective;
    }
}
