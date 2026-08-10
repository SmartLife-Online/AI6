<?php

namespace App\AI6\Git;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\Models\Project;

final class TicketReadModelResultBinding
{
    /** @param array{refresh_base_path: string, relative_path: string} $parameters */
    public function assertMatches(
        ControlOperation $operation,
        Project $project,
        array $parameters,
        TicketBlob $observedBlob,
        TicketReadModelRefreshResult $result,
    ): void {
        $matches = $operation->operation_type === ControlOperationType::TICKET_REFRESH
            && $operation->expected_control_commit !== null
            && hash_equals($operation->id, $result->operationId)
            && $operation->project_id === $result->projectId
            && $project->getKey() === $result->projectId
            && hash_equals($parameters['relative_path'], $observedBlob->relativePath)
            && hash_equals($parameters['relative_path'], $result->relativePath)
            && hash_equals($operation->expected_control_commit, $result->controlCommit)
            && hash_equals($observedBlob->blobSha, $result->blobSha)
            && hash_equals($observedBlob->content, $result->content);

        if (! $matches) {
            throw new ControlOperationTerminalConflict(
                'refresh_result_binding_mismatch',
                'Das Refresh-Ergebnis stimmt nicht mit seinem Auftrag und dem gelesenen Blob überein.',
            );
        }
    }
}
