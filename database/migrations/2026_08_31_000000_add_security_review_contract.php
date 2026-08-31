<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyGuard = $this->currentReviewGuard();
        DB::unprepared('DROP TRIGGER IF EXISTS review_results_insert_guard');
        Schema::table('review_results', function (Blueprint $table): void {
            $table->char('candidate_tree_sha', 64)->nullable();
            $table->char('candidate_diff_hash', 64)->nullable();
            $table->char('candidate_base_sha', 64)->nullable();
            $table->char('candidate_ticket_contract_sha256', 64)->nullable();
            $table->char('candidate_scope_hash', 64)->nullable();
            $table->char('candidate_prompt_snapshot_hash', 64)->nullable();
            $table->char('candidate_instruction_snapshot_hash', 64)->nullable();
            $table->string('candidate_agent_profile_id')->nullable();
            $table->char('candidate_runtime_profile_hash', 64)->nullable();
            $table->char('candidate_security_policy_hash', 64)->nullable();
        });
        DB::unprepared($this->securityReviewGuard($legacyGuard));
        $this->extendInterventionGuard(true);
    }

    public function down(): void
    {
        $this->extendInterventionGuard(false);
        $legacyGuard = $this->legacyGuard($this->currentReviewGuard());
        DB::unprepared('DROP TRIGGER IF EXISTS review_results_insert_guard');
        Schema::table('review_results', function (Blueprint $table): void {
            $table->dropColumn([
                'candidate_tree_sha', 'candidate_diff_hash', 'candidate_base_sha',
                'candidate_ticket_contract_sha256', 'candidate_scope_hash',
                'candidate_prompt_snapshot_hash', 'candidate_instruction_snapshot_hash',
                'candidate_agent_profile_id', 'candidate_runtime_profile_hash',
                'candidate_security_policy_hash',
            ]);
        });
        DB::unprepared($legacyGuard);
    }

    private function securityReviewGuard(string $legacy): string
    {
        $legacy = str_replace(
            "NEW.role NOT IN ('quality_review', 'finding_verification')",
            "NEW.role NOT IN ('quality_review', 'finding_verification', 'security_review')",
            $legacy,
            $roleCount,
        );
        $legacy = str_replace(
            "OR (NEW.role = 'finding_verification' AND NEW.invocation_outcome = 'valid_result' AND NEW.result_status NOT IN ('clear', 'inconclusive'))",
            "OR (NEW.role = 'finding_verification' AND NEW.invocation_outcome = 'valid_result' AND NEW.result_status NOT IN ('clear', 'inconclusive'))\n              OR (NEW.role = 'security_review' AND NEW.invocation_outcome = 'valid_result' AND NEW.result_status NOT IN ('clear', 'security_findings', 'needs_human', 'inconclusive'))",
            $legacy,
            $statusCount,
        );
        $legacy = str_replace(
            "OR (NEW.role = 'quality_review' AND (NEW.original_finding_id IS NOT NULL",
            "OR (NEW.role IN ('quality_review', 'security_review') AND (NEW.original_finding_id IS NOT NULL",
            $legacy,
            $verificationCount,
        );
        $candidate = $this->candidateClause();
        $legacy = str_replace(
            "BEGIN SELECT RAISE(ABORT, 'invalid review result'); END",
            $candidate."BEGIN SELECT RAISE(ABORT, 'invalid review result'); END",
            $legacy,
            $candidateCount,
        );
        if ($roleCount !== 1 || $statusCount !== 1 || $verificationCount !== 1 || $candidateCount !== 1) {
            throw new RuntimeException('The published review-result guard could not be extended deterministically.');
        }

        return $legacy;
    }

    private function candidateClause(): string
    {
        return <<<'SQL'
              OR (NEW.role = 'security_review' AND (
                    NEW.candidate_tree_sha IS NULL OR NEW.candidate_diff_hash IS NULL OR NEW.candidate_base_sha IS NULL
                    OR NEW.candidate_ticket_contract_sha256 IS NULL OR NEW.candidate_scope_hash IS NULL
                    OR NEW.candidate_prompt_snapshot_hash IS NULL OR NEW.candidate_instruction_snapshot_hash IS NULL
                    OR NEW.candidate_agent_profile_id IS NULL OR NEW.candidate_agent_profile_id = ''
                    OR NEW.candidate_runtime_profile_hash IS NULL OR NEW.candidate_security_policy_hash IS NULL
                    OR NEW.slot_prompt_hash IS NULL OR NEW.slot_instruction_hash IS NULL OR NEW.slot_runtime_profile_hash IS NULL
                    OR NEW.candidate_prompt_snapshot_hash <> NEW.slot_prompt_hash
                    OR NEW.candidate_instruction_snapshot_hash <> NEW.slot_instruction_hash
                    OR NEW.candidate_runtime_profile_hash <> NEW.slot_runtime_profile_hash
                    OR length(NEW.candidate_tree_sha) <> 64 OR NEW.candidate_tree_sha GLOB '*[^0-9a-f]*'
                    OR length(NEW.candidate_diff_hash) <> 64 OR NEW.candidate_diff_hash GLOB '*[^0-9a-f]*'
                    OR length(NEW.candidate_base_sha) <> 64 OR NEW.candidate_base_sha GLOB '*[^0-9a-f]*'
                    OR length(NEW.candidate_ticket_contract_sha256) <> 64 OR NEW.candidate_ticket_contract_sha256 GLOB '*[^0-9a-f]*'
                    OR length(NEW.candidate_scope_hash) <> 64 OR NEW.candidate_scope_hash GLOB '*[^0-9a-f]*'
                    OR length(NEW.candidate_prompt_snapshot_hash) <> 64 OR NEW.candidate_prompt_snapshot_hash GLOB '*[^0-9a-f]*'
                    OR length(NEW.candidate_instruction_snapshot_hash) <> 64 OR NEW.candidate_instruction_snapshot_hash GLOB '*[^0-9a-f]*'
                    OR length(NEW.candidate_runtime_profile_hash) <> 64 OR NEW.candidate_runtime_profile_hash GLOB '*[^0-9a-f]*'
                    OR length(NEW.candidate_security_policy_hash) <> 64 OR NEW.candidate_security_policy_hash GLOB '*[^0-9a-f]*'
                ))
              OR (NEW.role <> 'security_review' AND (
                    NEW.candidate_tree_sha IS NOT NULL OR NEW.candidate_diff_hash IS NOT NULL OR NEW.candidate_base_sha IS NOT NULL
                    OR NEW.candidate_ticket_contract_sha256 IS NOT NULL OR NEW.candidate_scope_hash IS NOT NULL
                    OR NEW.candidate_prompt_snapshot_hash IS NOT NULL OR NEW.candidate_instruction_snapshot_hash IS NOT NULL
                    OR NEW.candidate_agent_profile_id IS NOT NULL OR NEW.candidate_runtime_profile_hash IS NOT NULL
                    OR NEW.candidate_security_policy_hash IS NOT NULL
                ))
SQL;
    }

    private function legacyGuard(string $security): string
    {
        $security = str_replace($this->candidateClause(), '', $security, $candidateCount);
        $security = str_replace(
            "NEW.role NOT IN ('quality_review', 'finding_verification', 'security_review')",
            "NEW.role NOT IN ('quality_review', 'finding_verification')",
            $security,
            $roleCount,
        );
        $security = str_replace(
            "\n              OR (NEW.role = 'security_review' AND NEW.invocation_outcome = 'valid_result' AND NEW.result_status NOT IN ('clear', 'security_findings', 'needs_human', 'inconclusive'))",
            '',
            $security,
            $statusCount,
        );
        $security = str_replace(
            "OR (NEW.role IN ('quality_review', 'security_review') AND (NEW.original_finding_id IS NOT NULL",
            "OR (NEW.role = 'quality_review' AND (NEW.original_finding_id IS NOT NULL",
            $security,
            $verificationCount,
        );
        if ($candidateCount !== 1 || $roleCount !== 1 || $statusCount !== 1 || $verificationCount !== 1) {
            throw new RuntimeException('The security review-result guard could not be reverted deterministically.');
        }

        return $security;
    }

    private function currentReviewGuard(): string
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'review_results_insert_guard'");
        if (! is_object($row) || ! is_string($row->sql ?? null)) {
            throw new RuntimeException('The published review-result guard is missing.');
        }

        return $row->sql;
    }

    private function extendInterventionGuard(bool $include): void
    {
        $name = 'interventions_audit_insert_guard';
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
        if (! is_object($row) || ! is_string($row->sql ?? null)) {
            throw new RuntimeException('The published intervention audit guard is missing.');
        }
        $old = $include
            ? "'manual_report','status_sync','manual_gate'"
            : "'manual_report','status_sync','manual_gate','security_gate'";
        $new = $include
            ? "'manual_report','status_sync','manual_gate','security_gate'"
            : "'manual_report','status_sync','manual_gate'";
        $sql = str_replace($old, $new, $row->sql, $count);
        if ($count !== 1) {
            throw new RuntimeException('The intervention audit guard could not be extended deterministically.');
        }
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($sql);
    }
};
