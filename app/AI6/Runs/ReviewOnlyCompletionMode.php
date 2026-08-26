<?php

namespace App\AI6\Runs;

enum ReviewOnlyCompletionMode: string
{
    case MANUAL = 'manual';
    case AUTOMATIC_AFTER_GATES = 'automatic_after_gates';

    public function narrowedTo(self $serverMaximum): self
    {
        if ($this === self::MANUAL || $serverMaximum === self::AUTOMATIC_AFTER_GATES) {
            return $this;
        }

        return self::MANUAL;
    }

    public function assertNotBroadenedFrom(self $approved): void
    {
        if ($approved === self::MANUAL && $this === self::AUTOMATIC_AFTER_GATES) {
            throw new RunTransitionConflict(
                'completion_mode_broadened',
                'A server rule may narrow the report completion mode, but never broaden it.',
            );
        }
    }
}
