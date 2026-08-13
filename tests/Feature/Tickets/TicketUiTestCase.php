<?php

namespace Tests\Feature\Tickets;

use App\AI6\Auth\Models\User;
use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\Models\ControlOperationResult;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketValidationProfile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Git\ControlOperationTestCase;

abstract class TicketUiTestCase extends ControlOperationTestCase
{
    protected function provisionedProject(User $actor): Project
    {
        $project = $this->registeredProject($actor);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/test-key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => str_repeat('a', 64),
        ])->save();

        return $project->refresh();
    }

    /**
     * Publish a read model through the guarded refresh seam: queue the control
     * operation, claim it, insert the projection under the SQLite guards, and
     * finish the operation so the project lock frees up for the next path.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function publishReadModel(
        User $actor,
        Project $project,
        string $relativePath,
        string $content,
        array $overrides = [],
        ?TicketValidationProfile $requiredProfile = null,
        bool $finishOperation = true,
        ?string $operationId = null,
    ): TicketReadModel {
        $operation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $actor,
            $project->refresh(),
            $relativePath,
            $operationId ?? (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $attemptToken = $this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('b', 32));
        self::assertIsInt($attemptToken);
        $operationParameters = json_decode($operation->operation_parameters_jcs, true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($operationParameters);

        $projection = $this->app->make(TicketReadModelProjector::class)->project(
            $content,
            $relativePath,
            TicketValidationProfile::GENERIC_V1,
            $requiredProfile,
        );

        $attributes = array_replace([
            'project_id' => $project->getKey(),
            'control_operation_id' => $operation->id,
            'relative_path' => $relativePath,
            'control_commit' => (string) $project->control_oid,
            'blob_sha' => hash('sha256', $content),
            'control_generation' => 0,
            'validation_profile' => $projection->profile->value,
            'effective_config_hash' => $operationParameters['effective_config_hash'],
            'document_state' => $projection->state,
            'ticket_contract_sha256' => $projection->contractHash,
            'validation_errors' => $projection->errors,
            'redacted_content' => $content,
            'redaction_state' => 'clear',
            'redaction_matches' => [],
            'source_blockers' => $projection->sourceBlockers,
            'approval_editor_eligible' => false,
            'generated_at' => Date::now(),
        ], $overrides);
        $attributes = $this->withDerivedEligibility($attributes, $overrides);

        $readModel = TicketReadModel::query()->create($attributes)->refresh();

        if ($finishOperation) {
            $this->finishOperation($operation->refresh(), $attemptToken);
        }

        return $readModel;
    }

    /**
     * Publish a bootstrap envelope exactly as the pre-validation refresher did:
     * unparsed, profile-free, hash-free and without any eligibility.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function publishUnparsedReadModel(
        User $actor,
        Project $project,
        string $relativePath,
        string $content,
        array $overrides = [],
        bool $finishOperation = true,
    ): TicketReadModel {
        return $this->publishReadModel($actor, $project, $relativePath, $content, array_replace([
            'validation_profile' => null,
            'document_state' => TicketDocumentState::UNPARSED,
            'ticket_contract_sha256' => null,
            'validation_errors' => [],
            'source_blockers' => ['unparsed'],
            'editor_eligible' => false,
            'approval_eligible' => false,
        ], $overrides), null, $finishOperation);
    }

    /** @return list<array<string, int|string>> */
    protected function redactionMatchFixture(): array
    {
        return [[
            'type' => RedactionMatchType::SECRET->value,
            'field' => 'content',
            'start' => 0,
            'length' => 8,
            'marker' => RedactionMatchType::SECRET->marker(),
            'fingerprint_version' => 1,
            'key_id' => 'app-key-v1',
            'fingerprint' => str_repeat('c', 64),
        ]];
    }

    protected function markProjectMovedOn(Project $project): void
    {
        $project->forceFill(['control_oid' => str_repeat('f', 64)])->save();
    }

    protected function finishOperation(
        ControlOperation $operation,
        int $attemptToken,
        ControlOperationState $state = ControlOperationState::COMPLETED,
        ?string $lastError = null,
    ): void {
        ControlOperationResult::query()->create([
            'control_operation_id' => $operation->id,
            'outcome' => $state === ControlOperationState::FAILED ? 'failed' : 'succeeded',
            'result_binding' => str_repeat('d', 64),
            'safe_summary' => 'Testabschluss der Refresh-Operation.',
        ]);
        ControlOperation::query()
            ->whereKey($operation->id)
            ->update([
                'state' => $state->value,
                'phase' => ControlOperationPhase::ATTEMPT_COMPLETED->value,
                'last_error' => $lastError,
                'completed_at' => Date::now(),
                'version' => DB::raw('version + 1'),
                'updated_at' => Date::now(),
            ]);
        self::assertTrue($this->app->make(ProjectOperationLease::class)->release(
            $operation->id,
            $operation->project_id,
            $attemptToken,
        ));
    }

    protected function validTicketMarkdown(string $id, string $status = 'todo', string $dependsOn = '[]', string $goal = 'Ziel des Tickets.'): string
    {
        return <<<MARKDOWN
        ---
        schema: ai6.ticket.v1
        id: {$id}
        title: "Ticket {$id}"
        status: {$status}
        depends_on: {$dependsOn}
        ---

        # {$id} — Ticket {$id}

        ## Goal

        {$goal}
        MARKDOWN;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function withDerivedEligibility(array $attributes, array $overrides): array
    {
        $state = $attributes['document_state'];
        $stateValue = $state instanceof TicketDocumentState ? $state->value : (string) $state;
        $clear = ($attributes['redaction_state'] ?? 'clear') === 'clear';
        $blockers = $attributes['source_blockers'] ?? [];

        if (! array_key_exists('editor_eligible', $overrides) && ! array_key_exists('editor_eligible', $attributes)) {
            $attributes['editor_eligible'] = match ($stateValue) {
                'valid' => $clear && $blockers === [],
                'invalid' => $clear && $blockers === ['invalid'],
                default => false,
            };
        }

        if (! array_key_exists('approval_eligible', $overrides) && ! array_key_exists('approval_eligible', $attributes)) {
            $attributes['approval_eligible'] = $stateValue === 'valid' && $clear && $blockers === [];
        }

        return $attributes;
    }
}
