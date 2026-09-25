<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('projects')->select(['id', 'control_oid', 'pending_control_oid'])->get() as $project) {
                foreach (['control_oid', 'pending_control_oid'] as $field) {
                    $oid = $project->{$field};
                    if ($oid !== null && (! is_string($oid)
                        || preg_match('/\A[0-9a-f]{64}\z/D', $oid) !== 1
                        || $oid === str_repeat('0', 64))) {
                        throw new RuntimeException('Invalid legacy Git binding: projects.'.$field.' id='.$project->id);
                    }
                }
            }
            Schema::table('projects', function (Blueprint $table): void {
                $table->string('object_format')->nullable();
            });
            DB::table('projects')->whereNotNull('control_oid')->orWhereNotNull('pending_control_oid')
                ->update(['object_format' => 'sha256']);
            $this->rewriteGuards(true);
            $condition = <<<'SQL'
                (NEW.object_format IS NOT NULL AND NEW.object_format NOT IN ('sha1', 'sha256'))
                OR (NEW.control_oid IS NOT NULL AND (
                    length(NEW.control_oid) <> CASE NEW.object_format WHEN 'sha1' THEN 40 WHEN 'sha256' THEN 64 ELSE -1 END
                    OR NEW.control_oid GLOB '*[^0-9a-f]*' OR NEW.control_oid NOT GLOB '*[1-9a-f]*'))
                OR (NEW.pending_control_oid IS NOT NULL AND (
                    length(NEW.pending_control_oid) <> CASE NEW.object_format WHEN 'sha1' THEN 40 WHEN 'sha256' THEN 64 ELSE -1 END
                    OR NEW.pending_control_oid GLOB '*[^0-9a-f]*' OR NEW.pending_control_oid NOT GLOB '*[1-9a-f]*'))
                SQL;
            DB::unprepared("CREATE TRIGGER projects_object_format_insert_guard BEFORE INSERT ON projects WHEN $condition BEGIN SELECT RAISE(ABORT, 'invalid project object format'); END");
            DB::unprepared("CREATE TRIGGER projects_object_format_update_guard BEFORE UPDATE ON projects WHEN (OLD.object_format IS NOT NULL AND NEW.object_format IS NOT OLD.object_format) OR $condition BEGIN SELECT RAISE(ABORT, 'invalid or changed project object format'); END");
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::table('projects')->where('object_format', 'sha1')->exists()
                || DB::table('control_operations')->where('operation_type', 'managed_clone')
                    ->whereRaw('length(target_control_oid) = 40')->exists()) {
                throw new RuntimeException('The legacy Git contract cannot represent SHA-1 projects or clone intents.');
            }
            $this->rewriteGuards(false);
            DB::unprepared('DROP TRIGGER projects_object_format_insert_guard');
            DB::unprepared('DROP TRIGGER projects_object_format_update_guard');
            DB::unprepared('ALTER TABLE projects DROP COLUMN object_format');
        });
    }

    private function rewriteGuards(bool $upgrade): void
    {
        // Only the existing OID predicates change. Hash, provenance and state predicates retain their bytes.
        $guards = [
            'control_branch_audit_insert_guard' => ['old_control_oid', 'new_control_oid'],
            'control_operations_insert_guard' => ['expected_control_commit', 'target_control_oid'],
            'control_operations_update_guard' => ['target_control_oid'],
            'project_config_drafts_insert_guard' => ['control_commit', 'blob_sha'],
            'project_config_snapshots_insert_guard' => ['control_commit', 'blob_sha'],
            'review_only_execution_insert_guard' => ['review_subject_base_sha', 'review_subject_source_sha'],
            'review_only_execution_update_guard' => ['review_subject_base_sha', 'review_subject_source_sha'],
            'review_results_insert_guard' => ['checkpoint_commit_sha', 'checkpoint_tree_sha', 'candidate_tree_sha', 'candidate_base_sha'],
            'run_gates_candidate_update_guard' => ['evidence_candidate_tree_sha'],
            'run_gates_update_guard' => ['checkpoint_commit_sha'],
            'run_intervention_state_update_guard' => ['confirmed_branch_publication_oid'],
            'runs_amendment_update_guard' => ['ticket_blob_sha'],
            'runs_candidate_update_guard' => ['candidate_tree_sha', 'candidate_base_sha', 'candidate_checkpoint_commit_sha'],
            'runs_insert_guard' => ['claim_parent_control_sha', 'initial_run_base_sha', 'run_base_sha'],
            'runs_publish_completion_update_guard' => ['branch_publication_target_oid', 'final_commit_oid', 'final_commit_tree_oid', 'final_commit_parent_oid', 'branch_publication_expected_oid'],
            'runs_update_guard' => ['run_base_sha', 'checkpoint_commit_sha', 'checkpoint_tree_sha'],
            'ticket_approvals_insert_guard' => ['reviewed_ticket_blob_sha', 'reviewed_control_sha', 'approved_ticket_blob_sha', 'approved_control_sha'],
            'ticket_approvals_update_guard' => ['reviewed_ticket_blob_sha', 'reviewed_control_sha', 'approved_ticket_blob_sha', 'approved_control_sha'],
            'ticket_mutations_insert_guard' => ['expected_ticket_blob_sha', 'expected_target_blob_sha', 'expected_target_tree_oid', 'prepared_commit_oid'],
            'ticket_mutations_update_guard' => ['expected_ticket_blob_sha', 'expected_target_blob_sha', 'expected_target_tree_oid', 'prepared_commit_oid'],
            'ticket_read_models_insert_guard' => ['control_commit', 'blob_sha'],
            'ticket_read_models_update_guard' => ['control_commit', 'blob_sha'],
            'findings_insert_guard' => ['checkpoint_tree_sha'],
            'finding_statuses_insert_guard' => ['checkpoint_tree_sha'],
        ];
        foreach ($guards as $name => $fields) {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name]);
            if ($row === null || ! is_string($row->sql)) {
                throw new RuntimeException('Missing Git object guard: '.$name);
            }
            $sql = $row->sql;
            $viaRun = in_array($name, ['review_results_insert_guard', 'run_gates_candidate_update_guard', 'run_gates_update_guard', 'findings_insert_guard', 'finding_statuses_insert_guard'], true);
            $projectId = $viaRun ? '(SELECT project_id FROM runs WHERE id = NEW.run_id)' : 'NEW.project_id';
            if (str_starts_with($name, 'ticket_mutations_')) {
                $projectId = '(SELECT project_id FROM control_operations WHERE id = NEW.status_operation_id)';
            }
            foreach ($fields as $field) {
                $formatLength = "CASE p.object_format WHEN 'sha1' THEN 40 WHEN 'sha256' THEN 64 ELSE -1 END";
                if ($field === 'target_control_oid') {
                    // An unbound first-clone intent is the sole bootstrap authority until worker confirmation.
                    $formatLength = "CASE WHEN p.object_format IS NULL AND NEW.operation_type = 'managed_clone' AND NEW.expected_control_commit IS NULL THEN CASE length(NEW.target_control_oid) WHEN 40 THEN 40 WHEN 64 THEN 64 ELSE -1 END ELSE $formatLength END";
                }
                $length = "COALESCE((SELECT $formatLength FROM projects p WHERE p.id = $projectId), -1)";
                $old = "length(NEW.$field) <> 64";
                $new = "(NEW.$field IS NULL OR length(NEW.$field) <> $length";
                if ($field !== 'branch_publication_expected_oid') {
                    $new .= " OR NEW.$field NOT GLOB '*[1-9a-f]*'";
                }
                $new .= ')';
                if ($field === 'expected_target_tree_oid') {
                    $new = "(NEW.$field <> '".str_repeat('0', 64)."' AND $new)";
                }
                if (in_array($name, ['findings_insert_guard', 'finding_statuses_insert_guard'], true)) {
                    $old = "NEW.$field NOT GLOB replace(hex(zeroblob(32)), '00', '[0-9a-f][0-9a-f]')";
                    $new = "($new OR NEW.$field GLOB '*[^0-9a-f]*')";
                }
                $sql = str_replace($upgrade ? $old : $new, $upgrade ? $new : $old, $sql, $count);
                if ($count !== 1) {
                    throw new RuntimeException('Unexpected Git object guard shape: '.$name.'.'.$field);
                }
            }
            DB::unprepared('DROP TRIGGER '.$name);
            DB::unprepared($sql);
        }
    }
};
