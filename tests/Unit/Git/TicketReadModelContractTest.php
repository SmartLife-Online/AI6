<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ControlOperationTerminalConflict;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\TicketBlob;
use App\AI6\Git\TicketReadModelRefreshResult;
use App\AI6\Git\TicketReadModelResultBinding;
use App\AI6\Projects\ControlGeneration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketDocumentState;
use App\AI6\Projects\TicketReadModelFreshness;
use App\AI6\Projects\TicketReadModelRedactionState;
use App\AI6\Projects\TicketReadModelUsePolicy;
use App\AI6\Tickets\TicketValidationConfiguration;
use App\AI6\Tickets\TicketValidationProfile;
use PHPUnit\Framework\TestCase;

final class TicketReadModelContractTest extends TestCase
{
    public function test_approval_and_editor_policy_is_split_and_fails_closed(): void
    {
        $policy = new TicketReadModelUsePolicy(new TicketValidationConfiguration(TicketValidationProfile::GENERIC_V1));

        $unparsed = $this->readModel(
            TicketDocumentState::UNPARSED,
            TicketReadModelRedactionState::CLEAR,
            false, false,
            ['unparsed'],
        );
        self::assertFalse($policy->allowsEditor($unparsed, true));
        self::assertFalse($policy->allowsApproval($unparsed, true));

        $redacted = $this->readModel(
            TicketDocumentState::VALID,
            TicketReadModelRedactionState::CONTENT_REDACTED,
            false, false,
            ['content_redacted'],
        );
        self::assertFalse($policy->allowsEditor($redacted, true));
        self::assertFalse($policy->allowsApproval($redacted, true));

        $invalid = $this->readModel(
            TicketDocumentState::INVALID,
            TicketReadModelRedactionState::CLEAR,
            true, false,
            ['invalid'],
        );
        self::assertTrue($policy->allowsEditor($invalid, true));
        self::assertFalse($policy->allowsApproval($invalid, true));

        $valid = $this->readModel(
            TicketDocumentState::VALID,
            TicketReadModelRedactionState::CLEAR,
            true, true,
            [],
        );
        self::assertTrue($policy->allowsEditor($valid, true));
        self::assertTrue($policy->allowsApproval($valid, true));
    }

    public function test_staleness_predicates_are_independent_read_time_comparisons(): void
    {
        $freshness = new TicketReadModelFreshness(new ControlGeneration);
        $project = (new Project)->forceFill([
            'id' => 7,
            'control_generation' => 4,
            'control_oid' => str_repeat('a', 64),
        ]);
        $model = (new TicketReadModel)->forceFill([
            'control_generation' => 4,
            'control_commit' => str_repeat('a', 64),
        ]);

        self::assertSame(['stale' => false, 'reasons' => []], $freshness->for($project, $model));

        $model->control_generation = 3;
        self::assertSame(
            ['stale' => true, 'reasons' => ['control_generation_mismatch']],
            $freshness->for($project, $model),
        );

        $model->control_generation = 4;
        $model->control_commit = str_repeat('b', 64);
        self::assertSame(
            ['stale' => true, 'reasons' => ['control_commit_mismatch']],
            $freshness->for($project, $model),
        );
        self::assertSame(4, $model->control_generation);
        self::assertSame(str_repeat('b', 64), $model->control_commit);
    }

    public function test_result_binding_rejects_every_foreign_context_dimension(): void
    {
        $operation = (new ControlOperation)->forceFill([
            'id' => '123e4567-e89b-42d3-a456-426614174000',
            'project_id' => 7,
            'operation_type' => ControlOperationType::TICKET_REFRESH,
            'expected_control_commit' => str_repeat('a', 64),
        ]);
        $project = (new Project)->forceFill(['id' => 7]);
        $parameters = ['refresh_base_path' => 'tickets', 'relative_path' => 'tickets/AI6-006F.md'];
        $blob = new TicketBlob('tickets/AI6-006F.md', str_repeat('b', 64), "content\n");
        $valid = new TicketReadModelRefreshResult(
            $operation->id,
            7,
            $blob->relativePath,
            str_repeat('a', 64),
            $blob->blobSha,
            $blob->content,
        );
        $binding = new TicketReadModelResultBinding;
        $binding->assertMatches($operation, $project, $parameters, $blob, $valid);

        $invalid = [
            new TicketReadModelRefreshResult('223e4567-e89b-42d3-a456-426614174000', 7, $valid->relativePath, $valid->controlCommit, $valid->blobSha, $valid->content),
            new TicketReadModelRefreshResult($valid->operationId, 8, $valid->relativePath, $valid->controlCommit, $valid->blobSha, $valid->content),
            new TicketReadModelRefreshResult($valid->operationId, 7, 'tickets/other.md', $valid->controlCommit, $valid->blobSha, $valid->content),
            new TicketReadModelRefreshResult($valid->operationId, 7, $valid->relativePath, str_repeat('c', 64), $valid->blobSha, $valid->content),
            new TicketReadModelRefreshResult($valid->operationId, 7, $valid->relativePath, $valid->controlCommit, str_repeat('d', 64), $valid->content),
            new TicketReadModelRefreshResult($valid->operationId, 7, $valid->relativePath, $valid->controlCommit, $valid->blobSha, 'other content'),
        ];
        foreach ($invalid as $result) {
            try {
                $binding->assertMatches($operation, $project, $parameters, $blob, $result);
                self::fail('A foreign refresh result binding was accepted.');
            } catch (ControlOperationTerminalConflict $exception) {
                self::assertSame('refresh_result_binding_mismatch', $exception->conflict);
            }
        }
    }

    public function test_production_status_uses_the_central_approval_and_editor_policy(): void
    {
        $root = dirname(__DIR__, 3);
        $status = file_get_contents($root.'/app/AI6/Projects/ProjectReadModelStatus.php');
        $view = file_get_contents($root.'/resources/views/projects/show.blade.php');
        self::assertIsString($status);
        self::assertIsString($view);
        self::assertStringContainsString('allowsEditor($readModel', $status);
        self::assertStringContainsString('allowsApproval($readModel', $status);
        self::assertStringNotContainsString('->approval_editor_eligible', $view);
    }

    /** @param list<string> $blockers */
    private function readModel(
        TicketDocumentState $documentState,
        TicketReadModelRedactionState $redactionState,
        bool $editorEligible,
        bool $approvalEligible,
        array $blockers,
    ): TicketReadModel {
        return (new TicketReadModel)->forceFill([
            'document_state' => $documentState,
            'validation_profile' => 'generic_v1',
            'ticket_contract_sha256' => $documentState === TicketDocumentState::VALID ? str_repeat('a', 64) : null,
            'redaction_state' => $redactionState,
            'approval_editor_eligible' => false,
            'editor_eligible' => $editorEligible,
            'approval_eligible' => $approvalEligible,
            'source_blockers' => $blockers,
        ]);
    }
}
