<?php

namespace Tests\Feature\Tickets;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\ProjectRole;
use App\AI6\Tickets\TicketValidationProfile;

final class TicketUiStateMatrixTest extends TicketUiTestCase
{
    public function test_only_fresh_profile_qualified_clear_projections_own_entries_per_state(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);

        $unparsed = $this->publishUnparsedReadModel(
            $administrator,
            $project,
            'tickets/U1.md',
            $this->validTicketMarkdown('U1', 'todo', '[]', 'Envelopeziel.'),
        );
        $invalid = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/B1.md',
            $this->validTicketMarkdown('B1', 'wip', '[]', 'Ungültiges Ziel.'),
        );
        $valid = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T1.md',
            $this->validTicketMarkdown('T1', 'todo', '[]', 'Gültiges Ziel.'),
        );
        $mismatch = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/P1.md',
            $this->validTicketMarkdown('P1', 'todo', '[]', 'Profilfremdes Ziel.'),
            [],
            TicketValidationProfile::AI6_DETAIL_V1,
        );
        $masked = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/R1.md',
            $this->validTicketMarkdown('R1', 'todo', '[]', 'Maskiertes Ziel.'),
            [
                'redaction_state' => 'content_redacted',
                'redaction_matches' => $this->redactionMatchFixture(),
                'source_blockers' => ['content_redacted'],
            ],
        );

        self::assertNull($unparsed->ticket_contract_sha256);
        self::assertNull($invalid->ticket_contract_sha256);
        self::assertNotNull($valid->ticket_contract_sha256);

        $this->assertEntries($administrator, $project, $unparsed, edit: false, approval: false);
        $this->assertEntries($administrator, $project, $invalid, edit: true, approval: false);
        $this->assertEntries($administrator, $project, $valid, edit: true, approval: false);
        $this->assertEntries($approver, $project, $valid, edit: false, approval: true);
        $this->assertEntries($administrator, $project, $mismatch, edit: false, approval: false);
        $this->assertEntries($administrator, $project, $masked, edit: false, approval: false);

        $this->markProjectMovedOn($project);
        $this->assertEntries($administrator, $project, $valid, edit: false, approval: false);
        $this->assertEntries($administrator, $project, $invalid, edit: false, approval: false);
    }

    public function test_a_changed_central_policy_decision_flips_the_display(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $approver = $this->createUser();
        $project = $this->provisionedProject($administrator);
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $valid = $this->publishReadModel(
            $administrator,
            $project,
            'tickets/T1.md',
            $this->validTicketMarkdown('T1', 'todo', '[]', 'Politikgebundenes Ziel.'),
        );

        $this->assertEntries($administrator, $project, $valid, edit: true, approval: false);
        $this->assertEntries($approver, $project, $valid, edit: false, approval: true);

        // The availability consumes the effective project configuration: once
        // the trusted default demands the detail profile, the same projection
        // becomes stale and loses both entries without a view change.
        $defaults = config('ai6.project_config.server_defaults');
        self::assertIsArray($defaults);
        $defaults['ticket_validation_profile'] = TicketValidationProfile::AI6_DETAIL_V1->value;
        config()->set('ai6.project_config.server_defaults', $defaults);
        $this->app->forgetInstance(EffectiveProjectConfiguration::class);

        $this->assertEntries($approver, $project, $valid, edit: false, approval: false);
    }

    private function assertEntries(
        User $actor,
        Project $project,
        TicketReadModel $readModel,
        bool $edit,
        bool $approval,
    ): void {
        $response = $this->actingAs($actor)->get(route('projects.tickets.show', [$project, $readModel]));
        $response->assertOk();

        if ($edit) {
            $response->assertSee('data-ai6-entry="edit"', false);
        } else {
            $response->assertDontSee('data-ai6-entry="edit"', false);
        }

        if ($approval) {
            $response->assertSee('data-ai6-entry="approval"', false);
        } else {
            $response->assertDontSee('data-ai6-entry="approval"', false);
        }
    }
}
