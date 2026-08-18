<?php

namespace Tests\Unit\HumanLoop;

use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\HumanLoop\HumanRequestClassifier;
use App\AI6\HumanLoop\HumanRequestRejected;
use App\AI6\Projects\ProjectAction;
use PHPUnit\Framework\TestCase;

final class HumanRequestClassifierTest extends TestCase
{
    public function test_approve_reject_is_classified_into_server_effects_and_permission(): void
    {
        $classification = (new HumanRequestClassifier)->classify(new HumanRequestProposal(
            'scope_extension',
            'Titel',
            'Nachricht',
            'Begründung',
            'approve_reject',
            [new HumanRequestOption('approve', 'Genehmigen'), new HumanRequestOption('reject', 'Ablehnen')],
            'approve',
            ['app/Example.php'],
            ['AC-01'],
        ));

        self::assertSame(['approve', 'reject'], $classification->allowedEffects);
        self::assertSame(ProjectAction::ANSWER_HUMAN_REQUEST, $classification->requiredAction);
        self::assertSame(hash('sha256', "approve\nreject"), $classification->requestedEffectBinding);
    }

    public function test_select_uses_option_keys_but_never_a_provider_permission(): void
    {
        $classification = (new HumanRequestClassifier)->classify(new HumanRequestProposal(
            'clarification',
            'Titel',
            'Nachricht',
            'Begründung',
            'select',
            [new HumanRequestOption('a', 'A'), new HumanRequestOption('b', 'B')],
            'a',
            [],
            [],
        ));

        self::assertSame(['a', 'b'], $classification->allowedEffects);
        self::assertSame(ProjectAction::ANSWER_HUMAN_REQUEST, $classification->requiredAction);
    }

    public function test_an_unknown_response_mode_is_rejected(): void
    {
        try {
            (new HumanRequestClassifier)->classify(new HumanRequestProposal(
                'clarification',
                'Titel',
                'Nachricht',
                'Begründung',
                'free_text',
                [new HumanRequestOption('a', 'A')],
                'a',
                [],
                [],
            ));
            self::fail('Expected an unclassified proposal.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('unclassified_proposal', $rejected->reason);
        }
    }

    public function test_an_option_key_outside_the_closed_charset_is_rejected(): void
    {
        try {
            (new HumanRequestClassifier)->classify(new HumanRequestProposal(
                'clarification',
                'Titel',
                'Nachricht',
                'Begründung',
                'select',
                [new HumanRequestOption('Option A', 'A'), new HumanRequestOption('b', 'B')],
                'b',
                [],
                [],
            ));
            self::fail('Expected an unclassified proposal.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('unclassified_proposal', $rejected->reason);
        }
    }

    public function test_the_reserved_cancel_effect_cannot_be_claimed_by_an_option(): void
    {
        try {
            (new HumanRequestClassifier)->classify(new HumanRequestProposal(
                'clarification',
                'Titel',
                'Nachricht',
                'Begründung',
                'select',
                [new HumanRequestOption('a', 'A'), new HumanRequestOption('cancel', 'Abbrechen')],
                'a',
                [],
                [],
            ));
            self::fail('Expected an unclassified proposal.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('unclassified_proposal', $rejected->reason);
        }
    }

    public function test_a_kind_outside_the_closed_charset_is_rejected(): void
    {
        try {
            (new HumanRequestClassifier)->classify(new HumanRequestProposal(
                'AKIAIOSFODNN7EXAMPLE secret',
                'Titel',
                'Nachricht',
                'Begründung',
                'select',
                [new HumanRequestOption('a', 'A')],
                'a',
                [],
                [],
            ));
            self::fail('Expected an unclassified proposal.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('unclassified_proposal', $rejected->reason);
        }
    }

    public function test_approve_reject_requires_exactly_the_server_keys(): void
    {
        try {
            (new HumanRequestClassifier)->classify(new HumanRequestProposal(
                'scope_extension',
                'Titel',
                'Nachricht',
                'Begründung',
                'approve_reject',
                [new HumanRequestOption('yes', 'Genehmigen'), new HumanRequestOption('no', 'Ablehnen')],
                'yes',
                [],
                [],
            ));
            self::fail('Expected an unclassified proposal.');
        } catch (HumanRequestRejected $rejected) {
            self::assertSame('unclassified_proposal', $rejected->reason);
        }
    }
}
