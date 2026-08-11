<?php

namespace Tests\Feature\Tickets;

use App\AI6\Git\Actions\QueueTicketReadModelRefresh;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\TicketReadModelUsePolicy;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketValidationProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Git\ControlOperationTestCase;

final class TicketProfileQualificationTest extends ControlOperationTestCase
{
    public function test_generic_projection_is_persisted_fail_closed_for_required_ai6_detail_profile(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $controlCommit = str_repeat('a', 64);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/test-key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => $controlCommit,
        ])->save();
        $operation = $this->app->make(QueueTicketReadModelRefresh::class)->handle(
            $administrator,
            $project->refresh(),
            'tickets/M169.md',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        self::assertIsInt($this->app->make(ProjectOperationLease::class)->claim($operation, str_repeat('a', 32)));

        $content = file_get_contents(base_path('tests/Fixtures/Tickets/generic-v1.md'));
        self::assertIsString($content);
        $projection = $this->app->make(TicketReadModelProjector::class)->project(
            $content,
            'tickets/M169.md',
            TicketValidationProfile::GENERIC_V1,
            TicketValidationProfile::AI6_DETAIL_V1,
        );
        self::assertSame(['validation_profile_mismatch'], $projection->sourceBlockers);

        $readModel = TicketReadModel::query()->create([
            'project_id' => $project->getKey(),
            'control_operation_id' => $operation->id,
            'relative_path' => 'tickets/M169.md',
            'control_commit' => $controlCommit,
            'blob_sha' => str_repeat('b', 64),
            'control_generation' => 0,
            'validation_profile' => TicketValidationProfile::GENERIC_V1->value,
            'document_state' => $projection->state,
            'ticket_contract_sha256' => $projection->contractHash,
            'validation_errors' => [],
            'redacted_content' => $content,
            'redaction_state' => 'clear',
            'redaction_matches' => [],
            'source_blockers' => $projection->sourceBlockers,
            'approval_editor_eligible' => false,
            'editor_eligible' => false,
            'approval_eligible' => false,
            'generated_at' => now(),
        ]);
        $readModel->refresh();
        self::assertSame('generic_v1', $readModel->validation_profile);
        self::assertSame(['validation_profile_mismatch'], $readModel->source_blockers);

        $policy = $this->app->make(TicketReadModelUsePolicy::class);
        self::assertFalse($policy->allowsEditor($readModel, true, TicketValidationProfile::AI6_DETAIL_V1));
        self::assertFalse($policy->allowsApproval($readModel, true, TicketValidationProfile::AI6_DETAIL_V1));
    }
}
