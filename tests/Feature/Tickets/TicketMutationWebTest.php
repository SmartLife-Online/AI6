<?php

namespace Tests\Feature\Tickets;

use App\AI6\Git\GitObjectFormat;
use App\AI6\Git\Models\TicketMutation;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Shared\Redaction\RedactionMatchType;
use App\AI6\Tickets\TicketMutationController;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

final class TicketMutationWebTest extends TicketUiTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function mutationRoutes(): iterable
    {
        yield 'edit' => [true];
        yield 'status' => [false];
    }

    #[DataProvider('mutationRoutes')]
    public function test_sha1_mutations_accept_only_nonzero_project_oids_through_the_step_up_route(bool $edit): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject($administrator);
        $project->forceFill([
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/test-key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => str_repeat('a', 40),
            'object_format' => GitObjectFormat::SHA1,
        ])->save();
        $content = $this->validTicketMarkdown('AI6-051-WEB');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/AI6-051-WEB.md', $content, [
            'blob_sha' => GitObjectFormat::SHA1->objectId('blob', $content),
        ]);
        $route = route($edit ? 'projects.tickets.update' : 'projects.tickets.status', [$project, $readModel]);
        $payload = [
            'operation_id' => (string) Str::uuid(),
            'expected_control_oid' => $readModel->control_commit,
            'expected_blob' => $readModel->blob_sha,
            'base_content' => $content,
            'reason' => 'Formatgebundene Mutation',
        ] + ($edit ? ['target_content' => str_replace('Ziel des Tickets.', 'Neues Ziel.', $content)] : ['status_operation' => 'block']);
        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => $edit
            ? TicketMutationController::EDIT_STEP_UP_ACTION : TicketMutationController::STATUS_STEP_UP_ACTION]), [
                'code' => $this->currentTotpCode($secret),
            ])->assertRedirect();
        foreach (['expected_control_oid', 'expected_blob'] as $field) {
            foreach ([str_repeat('a', 64), str_repeat('0', 40)] as $invalid) {
                $this->post($route, [...$payload, $field => $invalid])->assertSessionHasErrors($field);
                self::assertSame(0, TicketMutation::query()->count());
            }
        }
        $this->post($route, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $mutation = TicketMutation::query()->sole();
        self::assertSame($readModel->control_commit, $mutation->operation()->firstOrFail()->expected_control_commit);
        self::assertSame($readModel->blob_sha, $mutation->expected_ticket_blob_sha);
    }

    public function test_editor_displays_the_exact_clear_base_and_rejects_masked_projection(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $content = $this->validTicketMarkdown('W1', 'todo', '[]', 'Editierbares Ziel.');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/W1.md', $content);

        $this->actingAs($administrator)
            ->get(route('projects.tickets.edit', [$project, $readModel]))
            ->assertOk()
            ->assertSee($readModel->blob_sha)
            ->assertSee($readModel->ticket_contract_sha256)
            ->assertSee('Bitte Step-up bestätigen, bevor der Editorentwurf eingegeben wird')
            ->assertSee('Editierbares Ziel.');

        $maskedContent = $this->validTicketMarkdown('W2', 'todo', '[]', RedactionMatchType::SECRET->marker());
        $masked = $this->publishReadModel($administrator, $project, 'tickets/W2.md', $maskedContent, [
            'redaction_state' => TicketReadModelRedactionState::CONTENT_REDACTED,
            'redaction_matches' => $this->redactionMatchFixture(),
            'source_blockers' => ['content_redacted'],
            'editor_eligible' => false,
            'approval_eligible' => false,
        ]);

        $this->actingAs($administrator)
            ->get(route('projects.tickets.edit', [$project, $masked]))
            ->assertConflict();
    }

    public function test_mutation_route_requires_fresh_action_bound_step_up_before_queueing(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $content = $this->validTicketMarkdown('W3');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/W3.md', $content);

        $this->actingAs($administrator)->post(route('projects.tickets.update', [$project, $readModel]), [
            'operation_id' => (string) Str::uuid(),
            'expected_control_oid' => $readModel->control_commit,
            'expected_blob' => $readModel->blob_sha,
            'base_content' => $content,
            'target_content' => str_replace('Ziel des Tickets.', 'Neues Ziel.', $content),
            'reason' => 'Korrektur',
        ])->assertForbidden();

        self::assertSame(0, TicketMutation::query()->count());
    }

    public function test_viewer_sees_neither_editor_link_nor_mutation_route(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $viewer = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($viewer, $project, ProjectRole::VIEWER);
        $content = $this->validTicketMarkdown('W4');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/W4.md', $content);

        $this->actingAs($viewer)
            ->get(route('projects.tickets.show', [$project, $readModel]))
            ->assertOk()
            ->assertDontSee('data-ai6-entry="edit"', false);
        $this->actingAs($viewer)
            ->get(route('projects.tickets.edit', [$project, $readModel]))
            ->assertForbidden();
    }

    public function test_detail_displays_named_status_conflict_with_reload_path(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $project = $this->provisionedProject($administrator);
        $content = $this->validTicketMarkdown('W5');
        $readModel = $this->publishReadModel($administrator, $project, 'tickets/W5.md', $content);
        $this->markProjectMovedOn($project);

        $this->actingAs($administrator);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();
        $secret = $this->createConfirmedTotp($administrator);
        $this->post(route('auth.step-up.totp.verify', ['action' => TicketMutationController::STATUS_STEP_UP_ACTION]), [
            'code' => $this->currentTotpCode($secret),
        ])->assertRedirect();

        $response = $this->post(route('projects.tickets.status', [$project, $readModel]), [
            'operation_id' => (string) Str::uuid(),
            'expected_control_oid' => $readModel->control_commit,
            'expected_blob' => $readModel->blob_sha,
            'base_content' => $content,
            'reason' => 'Fachliche Blockierung',
            'status_operation' => 'block',
        ]);
        $response
            ->assertRedirect(route('projects.tickets.show', [$project, $readModel]))
            ->assertSessionHas('ticket_mutation_conflict', static fn (mixed $value): bool => is_array($value)
                && ($value['code'] ?? null) === 'editor_unavailable'
                && is_string($value['message'] ?? null));

        $this->get(route('projects.tickets.show', [$project, $readModel]))
            ->assertOk()
            ->assertSee('editor_unavailable')
            ->assertSee('Aktuellen Stand neu laden');
    }
}
