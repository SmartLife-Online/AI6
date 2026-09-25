<?php

namespace Tests\Feature\Git;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Pdo\Sqlite;

/** AI6-051/TC-03: probe direct writes at the actual valid application write boundary. */
trait AssertsGitObjectGuards
{
    /** @var array<string, true> */
    private array $formatGuardsSeen = [];

    /** @var list<string> */
    private array $formatGuardFailures = [];

    /** @var array<string, string> */
    private array $formatGuardHashFields = [];

    private bool $formatGuardProbeActive = false;

    /**
     * This independent inventory is deliberately not read from the migration.
     * The observer adds probes; it neither replaces nor disables a production guard.
     * SQLite supplies NEW values, so no SQL parser or duplicated fixture factory is needed.
     *
     * @param  list<string>  $guards
     */
    protected function observeGitObjectGuards(array $guards): void
    {
        $cases = [
            'control_branch_audit_insert_guard' => ['control_branch_audit_entries', 'INSERT', 'new_control_oid', null],
            'control_operations_insert_guard' => ['control_operations', 'INSERT', 'expected_control_commit', 'request_hash'],
            'control_operations_update_guard' => ['control_operations', 'UPDATE', 'target_control_oid', 'request_hash'],
            'project_config_drafts_insert_guard' => ['project_config_drafts', 'INSERT', 'blob_sha', 'config_hash'],
            'project_config_snapshots_insert_guard' => ['project_config_snapshots', 'INSERT', 'blob_sha', 'config_hash'],
            'review_only_execution_insert_guard' => ['runs', 'INSERT', 'review_subject_source_sha', null],
            'review_only_execution_update_guard' => ['runs', 'UPDATE', 'review_subject_source_sha', null],
            'review_results_insert_guard' => ['review_results', 'INSERT', 'checkpoint_tree_sha', 'workspace_tree_hash'],
            'run_gates_candidate_update_guard' => ['run_gates', 'UPDATE', 'evidence_candidate_tree_sha', 'evidence_candidate_diff_hash'],
            'run_gates_update_guard' => ['run_gates', 'UPDATE', 'checkpoint_commit_sha', null],
            'run_intervention_state_update_guard' => ['runs', 'UPDATE', 'confirmed_branch_publication_oid', null],
            'runs_amendment_update_guard' => ['runs', 'UPDATE', 'ticket_blob_sha', null],
            'runs_candidate_update_guard' => ['runs', 'UPDATE', 'candidate_tree_sha', 'candidate_diff_hash'],
            'runs_insert_guard' => ['runs', 'INSERT', 'run_base_sha', null],
            'runs_publish_completion_update_guard' => ['runs', 'UPDATE', 'final_commit_oid', null],
            'runs_update_guard' => ['runs', 'UPDATE', 'run_base_sha', null],
            'ticket_approvals_insert_guard' => ['ticket_approvals', 'INSERT', 'reviewed_ticket_blob_sha', 'config_hash'],
            'ticket_approvals_update_guard' => ['ticket_approvals', 'UPDATE', 'approved_ticket_blob_sha', null],
            'ticket_mutations_insert_guard' => ['ticket_mutations', 'INSERT', 'expected_ticket_blob_sha', 'base_content_sha256'],
            'ticket_mutations_update_guard' => ['ticket_mutations', 'UPDATE', 'expected_target_tree_oid', null],
            'ticket_read_models_insert_guard' => ['ticket_read_models', 'INSERT', 'blob_sha', 'ticket_contract_sha256'],
            'ticket_read_models_update_guard' => ['ticket_read_models', 'UPDATE', 'blob_sha', null],
            'findings_insert_guard' => ['findings', 'INSERT', 'checkpoint_tree_sha', 'diff_hash'],
            'finding_statuses_insert_guard' => ['finding_statuses', 'INSERT', 'checkpoint_tree_sha', null],
        ];
        self::assertCount(24, $cases);
        $messages = [
            'control_branch_audit_insert_guard' => 'invalid control branch audit entry',
            'control_operations_insert_guard' => 'invalid control operation',
            'control_operations_update_guard' => 'invalid control operation transition',
            'project_config_drafts_insert_guard' => 'invalid project config draft',
            'project_config_snapshots_insert_guard' => 'invalid project config snapshot',
            'review_only_execution_insert_guard' => 'invalid review-only execution binding',
            'review_only_execution_update_guard' => 'invalid review-only execution binding',
            'review_results_insert_guard' => 'invalid review result',
            'run_gates_candidate_update_guard' => 'invalid candidate gate evidence transition',
            'run_gates_update_guard' => 'invalid run gate transition',
            'run_intervention_state_update_guard' => 'invalid run intervention state',
            'runs_amendment_update_guard' => 'invalid run amendment transition',
            'runs_candidate_update_guard' => 'invalid candidate binding transition',
            'runs_insert_guard' => 'invalid run insert',
            'runs_publish_completion_update_guard' => 'invalid publish completion binding',
            'runs_update_guard' => 'invalid run transition',
            'ticket_approvals_insert_guard' => 'invalid ticket approval',
            'ticket_approvals_update_guard' => 'invalid ticket approval transition',
            'ticket_mutations_insert_guard' => 'invalid ticket mutation',
            'ticket_mutations_update_guard' => 'invalid ticket mutation transition',
            'ticket_read_models_insert_guard' => 'invalid ticket read model',
            'ticket_read_models_update_guard' => 'invalid ticket read model transition',
            'findings_insert_guard' => 'invalid finding contract',
            'finding_statuses_insert_guard' => 'invalid finding status contract',
        ];
        self::assertSame(array_keys($cases), array_keys($messages));
        // This replaces only the empty in-memory test connection before fixture setup.
        $pdo = new Sqlite('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        DB::connection()->setPdo($pdo);
        self::assertSame(0, Artisan::call('migrate:fresh'), Artisan::output());
        $pdo->createFunction('ai6_test_format_probe', function (string $guard, string $json) use ($cases, $messages): int {
            if ($this->formatGuardProbeActive) {
                return 1;
            }
            [$table, $event, $oidField, $hashField] = $cases[$guard];
            $row = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $oid = $row[$oidField] ?? null;
            if (! is_string($oid) || ! in_array(strlen($oid), [40, 64], true) || trim($oid, '0') === '') {
                return 1;
            }
            // A first-clone target has no bound project format yet and permits both lengths.
            if ($table === 'control_operations' && $row['operation_type'] === 'managed_clone' && $row['expected_control_commit'] === null) {
                return 1;
            }
            $changes = [$oidField => str_repeat('f', strlen($oid) === 40 ? 64 : 40)];
            if ($hashField !== null && is_string($row[$hashField] ?? null)) {
                $changes[$hashField] = str_repeat('f', 40);
            }
            foreach ($changes as $field => $invalid) {
                $key = $guard.'.'.$field;
                if (isset($this->formatGuardsSeen[$key])) {
                    continue;
                }
                $this->formatGuardProbeActive = true;
                try {
                    $bad = [...$row, $field => $invalid];
                    // A base change must invalidate an existing candidate first;
                    // otherwise its separate provenance trigger masks the OID guard.
                    if ($guard === 'runs_update_guard' && $row['candidate_tree_sha'] !== null) {
                        $bad['candidate_invalidated_at'] = now()->toDateTimeString();
                    }
                    // Probe the OID before publication confirmation: otherwise
                    // the publish tuple's target equality rejects it first.
                    if ($guard === 'run_intervention_state_update_guard') {
                        $bad['branch_publication_confirmed_at'] = null;
                        $bad['branch_publication_state'] = 'prepared';
                    }
                    if ($event === 'INSERT') {
                        DB::table($table)->insert($bad);
                    } else {
                        $primary = $table === 'ticket_mutations' ? 'status_operation_id' : 'id';
                        DB::table($table)->where($primary, $row[$primary])->update($bad);
                    }
                    $this->formatGuardFailures[] = $key.' accepted a foreign-format direct write';
                } catch (QueryException $exception) {
                    if (($exception->errorInfo[1] ?? null) !== 19
                        || ($exception->errorInfo[2] ?? null) !== $messages[$guard]) {
                        $this->formatGuardFailures[] = $key.': expected '.$messages[$guard]
                            .', got '.($exception->errorInfo[2] ?? $exception->getCode());
                    }
                    $this->formatGuardsSeen[$key] = true;
                } finally {
                    $this->formatGuardProbeActive = false;
                }
            }

            return 1;
        }, 2);
        foreach ($guards as $guard) {
            self::assertArrayHasKey($guard, $cases);
            [$table, $event, $oidField, $hashField] = $cases[$guard];
            if ($hashField !== null) {
                $this->formatGuardHashFields[$guard] = $hashField;
            }
            self::assertSame(1, DB::table('sqlite_master')->where('type', 'trigger')->where('name', $guard)->count());
            $columns = DB::select('PRAGMA table_info('.$table.')');
            $pairs = [];
            foreach ($columns as $column) {
                $pairs[] = "'".$column->name."', NEW.\"".$column->name.'"';
            }
            DB::unprepared('CREATE TEMP TRIGGER probe_'.$guard.' BEFORE '.$event.' ON '.$table
                ." BEGIN SELECT ai6_test_format_probe('".$guard."', json_object(".implode(', ', $pairs).')); END');
        }
    }

    /** @param list<string> $guards */
    protected function assertGitObjectGuardsObserved(array $guards): void
    {
        self::assertSame([], $this->formatGuardFailures);
        foreach ($guards as $guard) {
            self::assertNotEmpty(array_filter(array_keys($this->formatGuardsSeen), static fn (string $key): bool => str_starts_with($key, $guard.'.')), $guard.' had no valid write to probe');
            if (isset($this->formatGuardHashFields[$guard])) {
                self::assertArrayHasKey($guard.'.'.$this->formatGuardHashFields[$guard], $this->formatGuardsSeen, 'The independent checksum guard was not exercised.');
            }
        }
    }
}
