<?php

namespace App\AI6\Runs;

use App\AI6\Checks\BoundCheckProfiles;
use App\AI6\Checks\CheckPhase;
use App\AI6\Checks\CheckResultState;
use App\AI6\Checks\CheckRunner;
use App\AI6\Checks\Models\CheckResultRecord;
use App\AI6\Git\PublishCandidate;
use App\AI6\Runs\Models\Run;
use Throwable;

/** The single gate that decides whether a prospect may become the bound candidate. */
final readonly class CandidateGate
{
    public function __construct(
        private RunOrchestrator $runs,
        private RequiredReviewEvidence $reviewEvidence,
        private BoundCheckProfiles $profiles,
        private CheckRunner $checks,
    ) {}

    public function decide(Run $run, PublishCandidate $candidate): CandidateGateDecision
    {
        $blockers = $this->reviewEvidence->blockers($run);
        if ($this->runs->hasEffectiveBlockingFindings($run)) {
            $blockers[] = 'effective_finding_blocks_candidate';
        }
        try {
            $treeBinding = $this->checks->currentTreeBinding($run);
            foreach ([CheckPhase::BEFORE_REVIEW, CheckPhase::FINAL] as $phase) {
                $blockers = [...$blockers, ...$this->checkBlockers($run, $phase, $treeBinding)];
            }
        } catch (Throwable) {
            $blockers[] = 'candidate_check_tree_unavailable';
        }

        $open = $this->runs->invalidateStaleCandidateGateEvidence($run, $candidate);

        return new CandidateGateDecision(array_values(array_unique($blockers)), $open);
    }

    /** @return list<string> */
    private function checkBlockers(Run $run, CheckPhase $phase, string $treeBinding): array
    {
        $profiles = $this->profiles->forPhase($run, $phase);
        if ($profiles === null) {
            return ['candidate_'.$phase->value.'_check_snapshot_invalid'];
        }
        $blockers = [];
        foreach ($profiles as $profile) {
            $resultId = CheckResultRecord::query()->where('run_id', $run->id)
                ->where('phase', $phase->value)->where('profile', $profile)
                ->where('evidence_epoch', $run->evidence_epoch)->whereNull('superseded_at')
                ->orderByDesc('created_at')->value('id');
            $result = is_string($resultId) ? CheckResultRecord::query()->find($resultId) : null;
            if (! $result instanceof CheckResultRecord) {
                $blockers[] = 'candidate_'.$phase->value.'_check_missing:'.$profile;
            } elseif ($result->state !== CheckResultState::SUCCEEDED) {
                $blockers[] = 'candidate_'.$phase->value.'_check_not_succeeded:'.$profile;
            } elseif (! hash_equals($treeBinding, $result->tree_sha)
                || ! hash_equals($result->tree_sha, $result->result_tree_sha)) {
                $blockers[] = 'candidate_'.$phase->value.'_check_tree_mismatch:'.$profile;
            }
        }

        return $blockers;
    }
}
