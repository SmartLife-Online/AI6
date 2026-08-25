<?php

namespace App\AI6\Runs;

use App\AI6\Git\RunPatchChange;
use App\AI6\Runs\Models\Run;
use App\AI6\Runs\Models\RunArtifact;
use App\AI6\Runs\Models\RunLimitConsumption;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
        foreach (array_keys($maxima) as $name) {
            if (! is_string($name)) {
                throw new ImplementationImportException('limit_configuration_missing', 'The server limit names are invalid.');
            }
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

    /**
     * Atomically consume a run counter before its effect.
     *
     * The unique consumption key makes a repeated delivery of the same turn or
     * round free, while a genuinely new provider attempt receives a new key.
     */
    public function consume(Run $run, ImportLimit $limit, string $consumptionKey, int $quantity = 1): ?ImportLimitResult
    {
        if (! in_array($limit, [
            ImportLimit::MAX_AGENT_INVOCATIONS,
            ImportLimit::MAX_REVIEW_ROUNDS,
            ImportLimit::MAX_FIX_ROUNDS,
            ImportLimit::MAX_VERIFICATION_ROUNDS,
        ], true) || $consumptionKey === '' || $quantity < 1) {
            throw new ImplementationImportException('limit_consumption_invalid', 'The run limit consumption is invalid.');
        }

        return DB::transaction(function () use ($run, $limit, $consumptionKey, $quantity): ?ImportLimitResult {
            DB::table('runs')->where('id', $run->getKey())->lockForUpdate()->first();
            $existing = RunLimitConsumption::query()
                ->where('run_id', $run->getKey())
                ->where('limit_name', $limit->value)
                ->where('consumption_key', $consumptionKey)
                ->first();
            if ($existing instanceof RunLimitConsumption) {
                return null;
            }

            $used = (int) RunLimitConsumption::query()
                ->where('run_id', $run->getKey())
                ->where('limit_name', $limit->value)
                ->sum('quantity');
            $maximum = $this->effective($run)[$limit->value];
            if ($used + $quantity > $maximum) {
                return new ImportLimitResult($limit, $used + $quantity, $maximum);
            }

            try {
                RunLimitConsumption::query()->create([
                    'run_id' => $run->getKey(),
                    'limit_name' => $limit->value,
                    'consumption_key' => $consumptionKey,
                    'quantity' => $quantity,
                    'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A parallel delivery consumed this exact effect. It is not a
                // second invocation and therefore consumes nothing more.
            }

            return null;
        });
    }

    public function runtimeExceeded(Run $run): ?ImportLimitResult
    {
        if ($run->created_at === null) {
            throw new ImplementationImportException('run_start_missing', 'The persisted run start is unavailable.');
        }
        $elapsed = (int) floor(max(0, $run->created_at->diffInMinutes(now())));
        $maximum = $this->effective($run)[ImportLimit::MAX_RUN_MINUTES->value];

        return $elapsed > $maximum
            ? new ImportLimitResult(ImportLimit::MAX_RUN_MINUTES, $elapsed, $maximum)
            : null;
    }

    /** @return array<string, int>|null */
    public function raiseOne(Run $run, ImportLimit $limit): ?array
    {
        if (! in_array($limit, [ImportLimit::MAX_REVIEW_ROUNDS, ImportLimit::MAX_FIX_ROUNDS, ImportLimit::MAX_VERIFICATION_ROUNDS], true)) {
            throw new ImplementationImportException('limit_grant_invalid', 'Only a round limit can receive one additional round.');
        }
        $effective = $this->effective($run);
        $current = $effective[$limit->value];
        $maximum = $this->serverMaximum($limit->value);
        if ($current >= $maximum) {
            return null;
        }
        $effective[$limit->value] = $current + 1;

        return $effective;
    }
}
