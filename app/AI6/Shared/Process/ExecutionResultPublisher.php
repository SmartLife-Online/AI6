<?php

namespace App\AI6\Shared\Process;

final readonly class ExecutionResultPublisher
{
    public function publish(ExecutionMailbox $mailbox, string $slotId, string $deliveryId, ProcessResult $result): ?string
    {
        if (! $result->succeeded() || $result->limitResult !== null) {
            return null;
        }

        return $mailbox->write(MailboxMessageType::RESULT, $slotId, $deliveryId, $result->output);
    }
}
