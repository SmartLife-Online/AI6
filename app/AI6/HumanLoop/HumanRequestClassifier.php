<?php

namespace App\AI6\HumanLoop;

use App\AI6\Agents\HumanRequestOption;
use App\AI6\Agents\HumanRequestProposal;
use App\AI6\Projects\ProjectAction;

/**
 * The server-side translation of an untrusted proposal into allowed effects.
 *
 * An option key is the only proposal text that survives as an identifier: it
 * becomes an allowed effect, part of the effect binding and a form value, so it
 * can neither be redacted nor stored as free text. It therefore passes a closed
 * character set here instead, and the reserved cancel effect stays server-owned.
 */
final class HumanRequestClassifier
{
    /** The closed character set an option key must match to become an effect. */
    private const KEY_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,31}\z/D';

    public function classify(HumanRequestProposal $proposal): HumanRequestClassification
    {
        // The kind is a type identifier, not prose, and is persisted verbatim;
        // it therefore passes the same closed character set as an option key.
        if (preg_match(self::KEY_PATTERN, $proposal->kind) !== 1) {
            throw new HumanRequestRejected(
                'unclassified_proposal',
                'The proposal kind is not a server-classified request identifier.',
            );
        }

        $effects = array_map(
            static fn (HumanRequestOption $option): string => $option->key,
            $proposal->options,
        );

        foreach ($effects as $effect) {
            if (preg_match(self::KEY_PATTERN, $effect) !== 1) {
                throw new HumanRequestRejected(
                    'unclassified_proposal',
                    'A proposal option key is not a server-classified effect identifier.',
                );
            }
            if ($effect === HumanRequestService::CANCEL_EFFECT) {
                throw new HumanRequestRejected(
                    'unclassified_proposal',
                    'A proposal must not claim the reserved cancel effect as an option.',
                );
            }
        }

        if ($effects === [] || array_values(array_unique($effects)) !== $effects) {
            throw new HumanRequestRejected(
                'unclassified_proposal',
                'The proposal does not yield a unique non-empty effect list.',
            );
        }

        if (! in_array($proposal->responseMode, ['approve_reject', 'select'], true)) {
            throw new HumanRequestRejected(
                'unclassified_proposal',
                'The proposal response mode is not a classified human-question effect.',
            );
        }
        // approve_reject is exactly the two named server decisions; select is
        // free to name its own keys, which the character set above already bounds.
        if ($proposal->responseMode === 'approve_reject' && $effects !== ['approve', 'reject']) {
            throw new HumanRequestRejected(
                'unclassified_proposal',
                'The proposal options do not match the effect vocabulary of its response mode.',
            );
        }

        return new HumanRequestClassification(
            $proposal->kind,
            $proposal->responseMode,
            $effects,
            ProjectAction::ANSWER_HUMAN_REQUEST,
            hash('sha256', implode("\n", $effects)),
        );
    }
}
