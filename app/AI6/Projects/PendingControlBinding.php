<?php

namespace App\AI6\Projects;

use App\AI6\Projects\Models\Project;
use RuntimeException;

final readonly class PendingControlBinding
{
    private function __construct(
        public string $ref,
        public string $oid,
        public string $sourceOperationId,
        public int $version,
    ) {}

    public static function fromProject(Project $project): ?self
    {
        $values = [
            $project->pending_control_ref,
            $project->pending_control_oid,
            $project->pending_control_operation_id,
        ];
        $present = array_map(static fn (mixed $value): bool => $value !== null, $values);
        if ($present === [false, false, false]) {
            return null;
        }
        if ($present !== [true, true, true]
            || ! is_string($project->pending_control_ref)
            || ! is_string($project->pending_control_oid)
            || preg_match('/\A[0-9a-f]{64}\z/D', $project->pending_control_oid) !== 1
            || ! is_string($project->pending_control_operation_id)
            || $project->control_binding_version < 0) {
            throw new RuntimeException('The pending control binding is incomplete or malformed.');
        }

        return new self(
            $project->pending_control_ref,
            $project->pending_control_oid,
            $project->pending_control_operation_id,
            $project->control_binding_version,
        );
    }
}
