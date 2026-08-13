<?php

namespace Tests\Unit\Tickets;

use App\AI6\Projects\Models\TicketReadModel;
use App\AI6\Projects\TicketReadModelUsePolicy;
use App\AI6\Tickets\TicketReadModelProjector;
use App\AI6\Tickets\TicketValidationError;
use App\AI6\Tickets\TicketValidationProfile;
use Tests\TestCase;

final class TicketProjectionPolicyTest extends TestCase
{
    public function test_projection_union_has_no_partial_hash_and_errors_are_value_free(): void
    {
        $projector = $this->app->make(TicketReadModelProjector::class);
        $invalid = $projector->project("---\nid: SECRET-VALUE\n", 'tickets/M169.md', TicketValidationProfile::GENERIC_V1);
        self::assertSame('invalid', $invalid->state->value);
        self::assertNull($invalid->contractHash);
        self::assertStringNotContainsString('SECRET-VALUE', json_encode($invalid->errors, JSON_THROW_ON_ERROR));

        $valid = $projector->project($this->fixture('generic-v1.md'), 'tickets/M169.md', TicketValidationProfile::GENERIC_V1);
        self::assertSame('valid', $valid->state->value);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $valid->contractHash ?? '');
        self::assertSame([], $valid->errors);

        $mismatched = $projector->project(
            $this->fixture('generic-v1.md'),
            'tickets/M169.md',
            TicketValidationProfile::GENERIC_V1,
            TicketValidationProfile::AI6_DETAIL_V1,
        )->withErrors([new TicketValidationError('project_error', 'tickets/M169.md', 'Projektfehler.')]);
        self::assertSame(['invalid', 'validation_profile_mismatch'], $mismatched->sourceBlockers);
    }

    public function test_invalid_is_editor_only_while_redaction_staleness_and_profile_mismatch_fail_closed(): void
    {
        $policy = $this->app->make(TicketReadModelUsePolicy::class);
        $invalid = $this->readModel('invalid', null, ['invalid'], true, false);
        self::assertTrue($policy->allowsEditor($invalid, true, TicketValidationProfile::GENERIC_V1));
        self::assertFalse($policy->allowsApproval($invalid, true, TicketValidationProfile::GENERIC_V1));
        self::assertFalse($policy->allowsEditor($invalid, false, TicketValidationProfile::GENERIC_V1));
        self::assertFalse($policy->allowsEditor($invalid, true, TicketValidationProfile::AI6_DETAIL_V1));

        $valid = $this->readModel('valid', str_repeat('a', 64), [], true, true);
        self::assertTrue($policy->allowsEditor($valid, true, TicketValidationProfile::GENERIC_V1));
        self::assertTrue($policy->allowsApproval($valid, true, TicketValidationProfile::GENERIC_V1));
        $valid->setRawAttributes([...$valid->getAttributes(), 'redaction_state' => 'content_redacted'], true);
        self::assertFalse($policy->allowsEditor($valid, true, TicketValidationProfile::GENERIC_V1));
        self::assertFalse($policy->allowsApproval($valid, true, TicketValidationProfile::GENERIC_V1));
    }

    /** @param list<string> $blockers */
    private function readModel(string $state, ?string $hash, array $blockers, bool $editor, bool $approval): TicketReadModel
    {
        $model = new TicketReadModel;
        $model->setRawAttributes([
            'validation_profile' => 'generic_v1',
            'document_state' => $state,
            'ticket_contract_sha256' => $hash,
            'redaction_state' => 'clear',
            'source_blockers' => json_encode($blockers, JSON_THROW_ON_ERROR),
            'editor_eligible' => $editor,
            'approval_eligible' => $approval,
            'approval_editor_eligible' => false,
        ], true);

        return $model;
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(base_path('tests/Fixtures/Tickets/'.$name));
        self::assertIsString($content);

        return $content;
    }
}
