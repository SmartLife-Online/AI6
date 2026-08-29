<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS review_results_insert_guard');
        DB::statement('ALTER TABLE review_results ADD COLUMN original_finding_id TEXT NULL REFERENCES findings(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE review_results ADD COLUMN original_duplicate_group TEXT NULL');
        DB::statement('ALTER TABLE review_results ADD COLUMN verification_assessment TEXT NULL');
        DB::statement('ALTER TABLE review_results ADD COLUMN verification_recommendation TEXT NULL');
        DB::statement('ALTER TABLE review_results ADD COLUMN verification_evidence TEXT NULL');
        DB::statement('CREATE INDEX review_results_verification_finding_index ON review_results (run_id, original_finding_id)');
        DB::statement('CREATE INDEX review_results_verification_group_index ON review_results (run_id, original_duplicate_group)');
        $this->createInsertGuard();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS review_results_insert_guard');
        DB::statement('DROP INDEX IF EXISTS review_results_verification_finding_index');
        DB::statement('DROP INDEX IF EXISTS review_results_verification_group_index');
        foreach (['verification_evidence', 'verification_recommendation', 'verification_assessment', 'original_duplicate_group', 'original_finding_id'] as $column) {
            DB::statement('ALTER TABLE review_results DROP COLUMN '.$column);
        }
        DB::unprepared($this->legacyGuardSql());
    }

    private function createInsertGuard(): void
    {
        DB::unprepared($this->guardSql());
    }

    private function guardSql(): string
    {
        return <<<'SQL'
            CREATE TRIGGER review_results_insert_guard BEFORE INSERT ON review_results
            WHEN NEW.round_number < 1 OR NEW.attempt < 1 OR NEW.role NOT IN ('quality_review', 'finding_verification')
              OR NEW.provider_profile = '' OR NEW.model = '' OR NEW.effort = '' OR NEW.prompt_profile = ''
              OR NEW.invocation_outcome NOT IN ('valid_result', 'needs_human', 'provider_error', 'invalid_json', 'binding_error', 'checkpoint_error', 'workspace_error', 'human_request_error')
              OR (NEW.role = 'quality_review' AND NEW.invocation_outcome = 'valid_result' AND NEW.result_status NOT IN ('nothing_to_fix', 'findings_to_fix'))
              OR (NEW.role = 'finding_verification' AND NEW.invocation_outcome = 'valid_result' AND NEW.result_status NOT IN ('clear', 'inconclusive'))
              OR (NEW.invocation_outcome = 'valid_result' AND (NEW.failure_code IS NOT NULL OR NEW.raw_artifact_id IS NULL))
              OR (NEW.invocation_outcome = 'needs_human' AND (NEW.result_status <> 'needs_human' OR NEW.failure_code IS NOT NULL OR NEW.raw_artifact_id IS NULL))
              OR (NEW.invocation_outcome NOT IN ('valid_result', 'needs_human') AND (NEW.failure_code IS NULL OR NEW.result_status IS NOT NULL))
              OR (NEW.role = 'quality_review' AND (NEW.original_finding_id IS NOT NULL OR NEW.original_duplicate_group IS NOT NULL OR NEW.verification_assessment IS NOT NULL OR NEW.verification_recommendation IS NOT NULL OR NEW.verification_evidence IS NOT NULL))
              OR (NEW.role = 'finding_verification' AND (NEW.invocation_outcome = 'valid_result' AND ((NEW.original_finding_id IS NULL) = (NEW.original_duplicate_group IS NULL) OR NEW.verification_assessment NOT IN ('confirmed','contradicted','inconclusive') OR NEW.verification_recommendation NOT IN ('confirm','not_applicable','investigate') OR NEW.verification_evidence IS NULL OR NEW.verification_evidence = '' OR (NEW.verification_assessment = 'inconclusive') <> (NEW.result_status = 'inconclusive'))))
              OR (NEW.slot_prompt_hash IS NULL) <> (NEW.slot_instruction_hash IS NULL)
              OR (NEW.slot_prompt_hash IS NULL) <> (NEW.slot_runtime_profile_hash IS NULL)
              OR (NEW.workspace_tree_hash IS NULL AND NEW.invocation_outcome IN ('valid_result', 'needs_human', 'provider_error', 'invalid_json'))
              OR length(NEW.checkpoint_commit_sha) <> 64 OR NEW.checkpoint_commit_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.checkpoint_tree_sha) <> 64 OR NEW.checkpoint_tree_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.diff_hash) <> 64 OR NEW.diff_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_config_hash) <> 64 OR NEW.approval_config_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_scope_hash) <> 64 OR NEW.approval_scope_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_prompt_hash) <> 64 OR NEW.approval_prompt_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_instruction_hash) <> 64 OR NEW.approval_instruction_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_runtime_profile_hash) <> 64 OR NEW.approval_runtime_profile_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_agent_profile_hash) <> 64 OR NEW.approval_agent_profile_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_security_policy_hash) <> 64 OR NEW.approval_security_policy_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_snapshot_hash) <> 64 OR NEW.approval_snapshot_hash GLOB '*[^0-9a-f]*'
              OR (NEW.slot_prompt_hash IS NOT NULL AND (length(NEW.slot_prompt_hash) <> 64 OR NEW.slot_prompt_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.slot_instruction_hash IS NOT NULL AND (length(NEW.slot_instruction_hash) <> 64 OR NEW.slot_instruction_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.slot_runtime_profile_hash IS NOT NULL AND (length(NEW.slot_runtime_profile_hash) <> 64 OR NEW.slot_runtime_profile_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.workspace_tree_hash IS NOT NULL AND (length(NEW.workspace_tree_hash) <> 64 OR NEW.workspace_tree_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.raw_artifact_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM run_artifacts artifact WHERE artifact.id = NEW.raw_artifact_id AND artifact.run_id = NEW.run_id AND artifact.kind = 'provider_raw'))
              OR (NEW.original_finding_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM findings finding WHERE finding.id = NEW.original_finding_id AND finding.run_id = NEW.run_id AND finding.checkpoint_tree_sha = NEW.checkpoint_tree_sha AND finding.diff_hash = NEW.diff_hash))
              OR (NEW.original_duplicate_group IS NOT NULL AND NOT EXISTS (SELECT 1 FROM findings finding WHERE finding.run_id = NEW.run_id AND finding.duplicate_group = NEW.original_duplicate_group AND finding.checkpoint_tree_sha = NEW.checkpoint_tree_sha AND finding.diff_hash = NEW.diff_hash))
            BEGIN SELECT RAISE(ABORT, 'invalid review result'); END
            SQL;
    }

    private function legacyGuardSql(): string
    {
        return <<<'SQL'
            CREATE TRIGGER review_results_insert_guard BEFORE INSERT ON review_results
            WHEN NEW.round_number < 1 OR NEW.attempt < 1 OR NEW.role <> 'quality_review'
              OR NEW.provider_profile = '' OR NEW.model = '' OR NEW.effort = '' OR NEW.prompt_profile = ''
              OR NEW.invocation_outcome NOT IN ('valid_result', 'needs_human', 'provider_error', 'invalid_json', 'binding_error', 'checkpoint_error', 'workspace_error', 'human_request_error')
              OR (NEW.invocation_outcome = 'valid_result' AND (NEW.result_status NOT IN ('nothing_to_fix', 'findings_to_fix') OR NEW.failure_code IS NOT NULL OR NEW.raw_artifact_id IS NULL))
              OR (NEW.invocation_outcome = 'needs_human' AND (NEW.result_status <> 'needs_human' OR NEW.failure_code IS NOT NULL OR NEW.raw_artifact_id IS NULL))
              OR (NEW.invocation_outcome NOT IN ('valid_result', 'needs_human') AND (NEW.failure_code IS NULL OR NEW.result_status IS NOT NULL))
              OR (NEW.slot_prompt_hash IS NULL) <> (NEW.slot_instruction_hash IS NULL)
              OR (NEW.slot_prompt_hash IS NULL) <> (NEW.slot_runtime_profile_hash IS NULL)
              OR (NEW.workspace_tree_hash IS NULL AND NEW.invocation_outcome IN ('valid_result', 'needs_human', 'provider_error', 'invalid_json'))
              OR length(NEW.checkpoint_commit_sha) <> 64 OR NEW.checkpoint_commit_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.checkpoint_tree_sha) <> 64 OR NEW.checkpoint_tree_sha GLOB '*[^0-9a-f]*'
              OR length(NEW.diff_hash) <> 64 OR NEW.diff_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_config_hash) <> 64 OR NEW.approval_config_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_scope_hash) <> 64 OR NEW.approval_scope_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_prompt_hash) <> 64 OR NEW.approval_prompt_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_instruction_hash) <> 64 OR NEW.approval_instruction_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_runtime_profile_hash) <> 64 OR NEW.approval_runtime_profile_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_agent_profile_hash) <> 64 OR NEW.approval_agent_profile_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_security_policy_hash) <> 64 OR NEW.approval_security_policy_hash GLOB '*[^0-9a-f]*'
              OR length(NEW.approval_snapshot_hash) <> 64 OR NEW.approval_snapshot_hash GLOB '*[^0-9a-f]*'
              OR (NEW.slot_prompt_hash IS NOT NULL AND (length(NEW.slot_prompt_hash) <> 64 OR NEW.slot_prompt_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.slot_instruction_hash IS NOT NULL AND (length(NEW.slot_instruction_hash) <> 64 OR NEW.slot_instruction_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.slot_runtime_profile_hash IS NOT NULL AND (length(NEW.slot_runtime_profile_hash) <> 64 OR NEW.slot_runtime_profile_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.workspace_tree_hash IS NOT NULL AND (length(NEW.workspace_tree_hash) <> 64 OR NEW.workspace_tree_hash GLOB '*[^0-9a-f]*'))
              OR (NEW.raw_artifact_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM run_artifacts artifact WHERE artifact.id = NEW.raw_artifact_id AND artifact.run_id = NEW.run_id AND artifact.kind = 'provider_raw'))
            BEGIN SELECT RAISE(ABORT, 'invalid review result'); END
            SQL;
    }
};
