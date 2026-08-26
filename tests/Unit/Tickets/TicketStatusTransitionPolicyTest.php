<?php

namespace Tests\Unit\Tickets;

use App\AI6\Projects\ProjectRole;
use App\AI6\Tickets\TicketMutationConflict;
use App\AI6\Tickets\TicketStatusOperation;
use App\AI6\Tickets\TicketStatusTransitionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TicketStatusTransitionPolicyTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function test_human_owned_transitions_have_fixed_targets(
        string $source,
        TicketStatusOperation $operation,
        string $target,
        ProjectRole $role,
    ): void {
        self::assertSame($target, (new TicketStatusTransitionPolicy)->decide(
            $role,
            $operation,
            $source,
            true,
            str_repeat('a', 64),
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('b', 64),
            $operation === TicketStatusOperation::COMPLETE_REVIEW,
        ));
    }

    /** @return iterable<string, array{string, TicketStatusOperation, string, ProjectRole}> */
    public static function allowedTransitions(): iterable
    {
        yield 'todo blocked' => ['todo', TicketStatusOperation::BLOCK, 'blocked', ProjectRole::OPERATOR];
        yield 'todo cancelled' => ['todo', TicketStatusOperation::CANCEL, 'cancelled', ProjectRole::ADMIN];
        yield 'ready todo' => ['ready', TicketStatusOperation::RETURN_TO_TODO, 'todo', ProjectRole::OPERATOR];
        yield 'ready blocked' => ['ready', TicketStatusOperation::BLOCK, 'blocked', ProjectRole::ADMIN];
        yield 'ready cancelled' => ['ready', TicketStatusOperation::CANCEL, 'cancelled', ProjectRole::OPERATOR];
        yield 'blocked todo' => ['blocked', TicketStatusOperation::RETURN_TO_TODO, 'todo', ProjectRole::ADMIN];
        yield 'blocked cancelled' => ['blocked', TicketStatusOperation::CANCEL, 'cancelled', ProjectRole::OPERATOR];
        yield 'review done' => ['review', TicketStatusOperation::COMPLETE_REVIEW, 'done', ProjectRole::APPROVER];
        yield 'review todo' => ['review', TicketStatusOperation::RETURN_TO_TODO, 'todo', ProjectRole::APPROVER];
        yield 'review cancelled' => ['review', TicketStatusOperation::CANCEL, 'cancelled', ProjectRole::ADMIN];
        yield 'in_progress todo' => ['in_progress', TicketStatusOperation::RETURN_TO_TODO, 'todo', ProjectRole::OPERATOR];
        yield 'in_progress blocked' => ['in_progress', TicketStatusOperation::BLOCK, 'blocked', ProjectRole::APPROVER];
        yield 'in_progress cancelled approver' => ['in_progress', TicketStatusOperation::CANCEL, 'cancelled', ProjectRole::APPROVER];
        yield 'in_progress cancelled admin' => ['in_progress', TicketStatusOperation::CANCEL, 'cancelled', ProjectRole::ADMIN];
        yield 'in_progress cancelled operator' => ['in_progress', TicketStatusOperation::CANCEL, 'cancelled', ProjectRole::OPERATOR];
    }

    #[DataProvider('rejectedBindings')]
    public function test_transition_rejects_missing_security_binding(
        bool $stepUp,
        string $expectedBlob,
        string $actualBlob,
        string $expectedControl,
        string $actualControl,
        bool $externalConfirmed,
        string $conflict,
    ): void {
        try {
            (new TicketStatusTransitionPolicy)->decide(
                ProjectRole::APPROVER,
                TicketStatusOperation::COMPLETE_REVIEW,
                'review',
                $stepUp,
                $expectedBlob,
                $actualBlob,
                $expectedControl,
                $actualControl,
                $externalConfirmed,
            );
            self::fail('The transition unexpectedly succeeded.');
        } catch (TicketMutationConflict $exception) {
            self::assertSame($conflict, $exception->conflict);
        }
    }

    /** @return iterable<string, array{bool, string, string, string, string, bool, string}> */
    public static function rejectedBindings(): iterable
    {
        $blob = str_repeat('a', 64);
        $control = str_repeat('b', 64);

        yield 'step up' => [false, $blob, $blob, $control, $control, true, 'step_up_required'];
        yield 'blob' => [true, $blob, str_repeat('c', 64), $control, $control, true, 'ticket_blob_changed'];
        yield 'control' => [true, $blob, $blob, $control, str_repeat('d', 64), true, 'control_head_changed'];
        yield 'external confirmation' => [true, $blob, $blob, $control, $control, false, 'external_completion_unconfirmed'];
    }

    public function test_reserved_and_free_target_edges_are_not_representable(): void
    {
        self::assertSame(
            ['approve', 'block', 'cancel', 'return_to_todo', 'complete_review', 'complete_report_only'],
            array_map(static fn (TicketStatusOperation $operation): string => $operation->value, TicketStatusOperation::cases()),
        );
        $this->expectException(TicketMutationConflict::class);
        (new TicketStatusTransitionPolicy)->decide(
            ProjectRole::ADMIN,
            TicketStatusOperation::RETURN_TO_TODO,
            'todo',
            true,
            str_repeat('a', 64),
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('b', 64),
            false,
        );
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_each_transition_family_rejects_an_insufficient_role(
        ProjectRole $role,
        string $source,
        TicketStatusOperation $operation,
    ): void {
        try {
            (new TicketStatusTransitionPolicy)->decide(
                $role,
                $operation,
                $source,
                true,
                str_repeat('a', 64),
                str_repeat('a', 64),
                str_repeat('b', 64),
                str_repeat('b', 64),
                true,
            );
            self::fail('An insufficient role was accepted.');
        } catch (TicketMutationConflict $exception) {
            self::assertSame('transition_not_authorized', $exception->conflict);
        }
    }

    /** @return iterable<string, array{ProjectRole, string, TicketStatusOperation}> */
    public static function unauthorizedRoles(): iterable
    {
        yield 'viewer todo block' => [ProjectRole::VIEWER, 'todo', TicketStatusOperation::BLOCK];
        yield 'approver todo block' => [ProjectRole::APPROVER, 'todo', TicketStatusOperation::BLOCK];
        yield 'viewer ready return' => [ProjectRole::VIEWER, 'ready', TicketStatusOperation::RETURN_TO_TODO];
        yield 'approver blocked cancel' => [ProjectRole::APPROVER, 'blocked', TicketStatusOperation::CANCEL];
        yield 'operator review complete' => [ProjectRole::OPERATOR, 'review', TicketStatusOperation::COMPLETE_REVIEW];
        yield 'viewer in_progress cancel' => [ProjectRole::VIEWER, 'in_progress', TicketStatusOperation::CANCEL];
        yield 'admin in_progress block' => [ProjectRole::ADMIN, 'in_progress', TicketStatusOperation::BLOCK];
        yield 'operator in_progress block' => [ProjectRole::OPERATOR, 'in_progress', TicketStatusOperation::BLOCK];
        yield 'approver in_progress return' => [ProjectRole::APPROVER, 'in_progress', TicketStatusOperation::RETURN_TO_TODO];
    }
}
