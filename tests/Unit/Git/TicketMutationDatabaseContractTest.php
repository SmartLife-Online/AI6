<?php

namespace Tests\Unit\Git;

use App\AI6\Git\Actions\QueueTicketMutation;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\TicketReadModel;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Tickets\TicketUiTestCase;

final class TicketMutationDatabaseContractTest extends TicketUiTestCase
{
    public function test_mutation_guards_enforce_immutable_bindings_and_one_time_effect_oids(): void
    {
        [$operation, $mutation] = $this->queuedMutation();
        $token = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('d', 32));
        self::assertIsInt($token);

        $this->expectGuardRejection(static fn () => TicketMutation::query()
            ->whereKey($mutation->status_operation_id)
            ->update(['relative_path' => 'tickets/changed.md']));

        $tree = str_repeat('b', 64);
        self::assertSame(1, TicketMutation::query()->whereKey($mutation->status_operation_id)->update([
            'expected_target_tree_oid' => $tree,
        ]));
        $this->expectGuardRejection(static fn () => TicketMutation::query()
            ->whereKey($mutation->status_operation_id)
            ->update(['expected_target_tree_oid' => str_repeat('c', 64)]));

        $commit = str_repeat('d', 64);
        self::assertSame(1, TicketMutation::query()->whereKey($mutation->status_operation_id)->update([
            'prepared_commit_oid' => $commit,
            'prepared_attempt_token' => $token,
        ]));
        $this->expectGuardRejection(static fn () => TicketMutation::query()
            ->whereKey($mutation->status_operation_id)
            ->update(['prepared_commit_oid' => str_repeat('e', 64)]));
    }

    public function test_mutation_guards_reject_status_enums_and_impossible_operation_matrix(): void
    {
        [$operation, $mutation] = $this->queuedMutation();
        $row = (array) DB::table('ticket_mutations')->where('status_operation_id', $mutation->status_operation_id)->first();
        self::assertNotSame([], $row);
        DB::table('ticket_mutations')->where('status_operation_id', $mutation->status_operation_id)->delete();

        foreach ([['source_status', 'in_progress'], ['target_status', 'review']] as [$column, $value]) {
            $invalid = $row;
            $invalid[$column] = $value;
            $this->expectGuardRejection(static fn () => DB::table('ticket_mutations')->insert($invalid));
        }
        DB::table('ticket_mutations')->insert($row);

        $this->expectGuardRejection(static fn () => DB::table('control_operations')
            ->where('id', $operation->id)
            ->update([
                'state' => ControlOperationState::COMPLETED->value,
                'phase' => ControlOperationPhase::PREPARED->value,
            ]));
    }

    public function test_read_model_guard_accepts_mutation_only_at_control_confirmed(): void
    {
        [$operation, $mutation, $readModel] = $this->queuedMutation();
        $token = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('f', 32));
        self::assertIsInt($token);
        $commit = str_repeat('b', 64);

        $this->expectGuardRejection(static fn () => DB::table('ticket_read_models')
            ->where('id', $readModel->getKey())
            ->update(['control_operation_id' => $operation->id, 'control_commit' => $commit]));

        TicketMutation::query()->whereKey($mutation->status_operation_id)->update([
            'expected_target_tree_oid' => str_repeat('c', 64),
            'prepared_commit_oid' => $commit,
            'prepared_attempt_token' => $token,
        ]);
        DB::table('control_operations')->where('id', $operation->id)->update([
            'target_control_oid' => $commit,
            'phase' => ControlOperationPhase::CONTROL_CONFIRMED->value,
        ]);
        self::assertSame(1, DB::table('ticket_read_models')
            ->where('id', $readModel->getKey())
            ->update(['control_operation_id' => $operation->id, 'control_commit' => $commit]));
    }

    /** @return array{ControlOperation, TicketMutation, TicketReadModel} */
    private function queuedMutation(): array
    {
        $actor = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($actor);
        $base = $this->validTicketMarkdown('DB9', 'todo');
        $readModel = $this->publishReadModel($actor, $project, 'tickets/DB9.md', $base);
        $operation = $this->app->make(QueueTicketMutation::class)->edit(
            $actor,
            $project->refresh(),
            $readModel,
            (string) Str::uuid(),
            $readModel->control_commit,
            $readModel->blob_sha,
            $base,
            str_replace('Ziel des Tickets.', 'Datenbankvertrag.', $base),
            'Datenbankvertrag',
            true,
        );

        return [$operation, TicketMutation::query()->findOrFail($operation->id), $readModel];
    }

    private function expectGuardRejection(Closure $write): void
    {
        try {
            $write();
        } catch (QueryException) {
            return;
        }

        self::fail('An invalid ticket mutation database transition passed its guard.');
    }
}
