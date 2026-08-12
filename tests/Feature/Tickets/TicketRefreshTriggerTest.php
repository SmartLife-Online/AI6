<?php

namespace Tests\Feature\Tickets;

use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TicketRefreshTriggerTest extends TicketUiTestCase
{
    public function test_the_trigger_queues_exactly_one_operation_and_the_same_operation_id_stays_idempotent(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $operationId = (string) Str::uuid();
        $operationCountBefore = ControlOperation::query()
            ->where('operation_type', ControlOperationType::TICKET_REFRESH)
            ->count();
        DB::table('jobs')->delete();

        $first = $this->actingAs($administrator)->post(
            route('projects.ticket-read-model.refresh', $project),
            ['operation_id' => $operationId, 'relative_path' => 'tickets/T1.md'],
        );

        $first->assertRedirect(route('projects.operations.show', [$project, $operationId]));
        self::assertSame($operationCountBefore + 1, ControlOperation::query()
            ->where('operation_type', ControlOperationType::TICKET_REFRESH)
            ->count());
        self::assertSame(1, DB::table('jobs')->count());

        $second = $this->actingAs($administrator)->post(
            route('projects.ticket-read-model.refresh', $project),
            ['operation_id' => $operationId, 'relative_path' => 'tickets/T1.md'],
        );

        $second->assertRedirect(route('projects.operations.show', [$project, $operationId]));
        self::assertSame($operationCountBefore + 1, ControlOperation::query()
            ->where('operation_type', ControlOperationType::TICKET_REFRESH)
            ->count(), 'Repeating the same operation id must not create a second operation.');
        self::assertSame(1, DB::table('jobs')->count(), 'Repeating the same operation id must not queue a second job.');
    }

    public function test_commit_blob_time_and_staleness_predicate_are_displayed(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $readModel = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T1.md',
            $this->validTicketMarkdown('T1', 'todo', '[]', 'Bindungssichtbares Ziel.'),
        );
        $this->markProjectMovedOn($project);

        $response = $this->actingAs($administrator)->get(route('projects.tickets.show', [$project, $readModel]));

        $response->assertOk();
        $response->assertSee($readModel->control_commit);
        $response->assertSee($readModel->blob_sha);
        $response->assertSee($readModel->generated_at->toIso8601String());
        $response->assertSee('Veraltet');
        $response->assertSee('control_commit_mismatch');
        $response->assertSee('Refresh beauftragen');
    }
}
