<?php

namespace App\AI6\Tickets;

use App\AI6\Projects\ProjectRole;

final class TicketStatusTransitionPolicy
{
    /** @var array<string, list<ProjectRole>> */
    private const ROLES = [
        'todo:approve' => [ProjectRole::APPROVER],
        'todo:block' => [ProjectRole::ADMIN, ProjectRole::OPERATOR],
        'todo:cancel' => [ProjectRole::ADMIN, ProjectRole::OPERATOR],
        'ready:block' => [ProjectRole::ADMIN, ProjectRole::OPERATOR],
        'ready:cancel' => [ProjectRole::ADMIN, ProjectRole::OPERATOR],
        'ready:return_to_todo' => [ProjectRole::ADMIN, ProjectRole::OPERATOR],
        'blocked:cancel' => [ProjectRole::ADMIN, ProjectRole::OPERATOR],
        'blocked:return_to_todo' => [ProjectRole::ADMIN, ProjectRole::OPERATOR],
        'review:cancel' => [ProjectRole::ADMIN, ProjectRole::OPERATOR, ProjectRole::APPROVER],
        'review:return_to_todo' => [ProjectRole::ADMIN, ProjectRole::OPERATOR, ProjectRole::APPROVER],
        'review:complete_review' => [ProjectRole::ADMIN, ProjectRole::APPROVER],
        // The approver joins the published in_progress:cancel mapping because the
        // hard-cancel saga requires the approver role at the service boundary and
        // must pass the persisted revalidation; the published roles stay intact.
        // Additive extension explicitly human-approved on 25 August 2026 as a
        // scope/contract deviation of AI6-026 beyond the approved BLOCK source.
        'in_progress:cancel' => [ProjectRole::ADMIN, ProjectRole::OPERATOR, ProjectRole::APPROVER],
        'in_progress:return_to_todo' => [ProjectRole::ADMIN, ProjectRole::OPERATOR],
        'in_progress:block' => [ProjectRole::APPROVER],
    ];

    public function decide(
        ProjectRole $role,
        TicketStatusOperation $operation,
        string $sourceStatus,
        bool $freshStepUp,
        string $expectedBlob,
        string $actualBlob,
        string $expectedControlOid,
        string $actualControlOid,
        bool $externalCompletionConfirmed,
    ): string {
        $target = $operation->targetFor($sourceStatus);
        $roles = self::ROLES[$sourceStatus.':'.$operation->value] ?? [];

        if ($target === null || ! in_array($role, $roles, true)) {
            throw new TicketMutationConflict('transition_not_authorized', 'Der Statusübergang ist für Rolle und Operation nicht freigegeben.');
        }
        if (! $freshStepUp) {
            throw new TicketMutationConflict('step_up_required', 'Der Statusübergang verlangt eine frische Step-up-Bestätigung.');
        }
        if (! hash_equals($expectedBlob, $actualBlob)) {
            throw new TicketMutationConflict('ticket_blob_changed', 'Der erwartete Ticketblob ist veraltet.');
        }
        if (! hash_equals($expectedControlOid, $actualControlOid)) {
            throw new TicketMutationConflict('control_head_changed', 'Der erwartete Control-Head ist veraltet.');
        }
        if ($operation === TicketStatusOperation::COMPLETE_REVIEW && ! $externalCompletionConfirmed) {
            throw new TicketMutationConflict('external_completion_unconfirmed', 'Die externe Zusammenführung oder Abnahme ist nicht gebunden bestätigt.');
        }

        return $target;
    }

    /** Revalidates persisted authorization and freshly observed Git bindings in the worker. */
    public function decidePersisted(
        ProjectRole $role,
        TicketStatusOperation $operation,
        string $sourceStatus,
        string $expectedBlob,
        string $actualBlob,
        string $expectedControlOid,
        string $actualControlOid,
        bool $externalCompletionConfirmed,
    ): string {
        $target = $operation->targetFor($sourceStatus);
        $roles = self::ROLES[$sourceStatus.':'.$operation->value] ?? [];

        if ($target === null || ! in_array($role, $roles, true)) {
            throw new TicketMutationConflict('transition_not_authorized', "Der Status\u{00FC}bergang ist f\u{00FC}r Rolle und Operation nicht freigegeben.");
        }
        if (! hash_equals($expectedBlob, $actualBlob)) {
            throw new TicketMutationConflict('ticket_blob_changed', 'Der erwartete Ticketblob ist veraltet.');
        }
        if (! hash_equals($expectedControlOid, $actualControlOid)) {
            throw new TicketMutationConflict('control_head_changed', 'Der erwartete Control-Head ist veraltet.');
        }
        if ($operation === TicketStatusOperation::COMPLETE_REVIEW && ! $externalCompletionConfirmed) {
            throw new TicketMutationConflict('external_completion_unconfirmed', "Die externe Zusammenf\u{00FC}hrung oder Abnahme ist nicht gebunden best\u{00E4}tigt.");
        }

        return $target;
    }
}
