<?php

namespace App\AI6\Reviews;

use App\AI6\Git\CanonicalJson;
use App\AI6\Reviews\Models\Finding;
use App\AI6\Reviews\Models\FindingStatus;
use App\AI6\Runs\Models\Run;
use Illuminate\Database\Eloquent\Collection;

/** Builds the one deterministic, redacted finding package consumed by a fix turn. */
final readonly class FixContextPackage
{
    public function __construct(
        private EffectiveFindingState $states,
        private CanonicalJson $canonicalJson,
    ) {}

    /** @return array{json:string, hash:string, finding_ids:list<string>} */
    public function forRun(Run $run): array
    {
        $rows = [];
        foreach ($this->findings($run) as $finding) {
            if (! $this->states->blocks($finding, $run)) {
                continue;
            }
            $rows[] = [
                'id' => $finding->id,
                'source' => [
                    'slot_id' => $finding->slot_id,
                    'provider_profile' => $finding->provider_profile,
                    'model' => $finding->model,
                    'round_number' => $finding->round_number,
                    'checkpoint_tree_sha' => $finding->checkpoint_tree_sha,
                ],
                'evidence' => $finding->evidence,
                'expected_result' => $finding->expected_result,
                'criterion_refs' => $finding->criterion_refs,
                'location' => ['file' => $finding->file, 'line' => $finding->line],
                'title' => $finding->title,
            ];
        }
        $json = $this->canonicalJson->normalizeAndEncode(['findings' => $rows]);

        return [
            'json' => $json,
            'hash' => hash('sha256', "AI6-FIX-CONTEXT-V1\0".$json),
            'finding_ids' => array_column($rows, 'id'),
        ];
    }

    /** @return list<string> */
    public function priorFindingIds(Run $run, int $round): array
    {
        return $this->priorForRound($run, $round)['finding_ids'];
    }

    /** @return array{json:string, hash:string, finding_ids:list<string>} */
    public function priorForRound(Run $run, int $round): array
    {
        $findings = Finding::query()->where('run_id', $run->id)->where('round_number', '<', $round)
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('id')->get();
        $rows = [];
        foreach ($findings as $finding) {
            $rows[] = [
                'id' => $finding->id,
                'source' => [
                    'slot_id' => $finding->slot_id,
                    'provider_profile' => $finding->provider_profile,
                    'model' => $finding->model,
                    'round_number' => $finding->round_number,
                    'checkpoint_tree_sha' => $finding->checkpoint_tree_sha,
                ],
                'evidence' => $finding->evidence,
                'expected_result' => $finding->expected_result,
                'criterion_refs' => $finding->criterion_refs,
                'location' => ['file' => $finding->file, 'line' => $finding->line],
                'title' => $finding->title,
            ];
        }
        $json = $this->canonicalJson->normalizeAndEncode(['findings' => $rows]);

        return [
            'json' => $json,
            'hash' => hash('sha256', "AI6-REVIEW-PRIOR-FINDINGS-V1\0".$json),
            'finding_ids' => array_column($rows, 'id'),
        ];
    }

    /** @return Collection<int, Finding> */
    public function priorFindingsForRound(Run $run, int $round): Collection
    {
        return Finding::query()->with('dispositions')->where('run_id', $run->id)->where('round_number', '<', $round)
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('id')->get();
    }

    /** @return list<string> */
    public function fixedSlotIds(Run $run, Finding $finding, int $round): array
    {
        return FindingStatus::query()->where('run_id', $run->id)->where('finding_id', $finding->id)
            ->where('round_number', $round)->where('checkpoint_tree_sha', $run->checkpoint_tree_sha)
            ->where('source_role', 'quality_review')
            ->where('status', FindingReviewStatus::FIXED->value)->pluck('slot_id')->all();
    }

    /** @return Collection<int, Finding> */
    private function findings(Run $run): Collection
    {
        return Finding::query()->with('dispositions')->where('run_id', $run->id)
            ->orderBy('round_number')->orderBy('slot_id')->orderBy('id')->get();
    }
}
