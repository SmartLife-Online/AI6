<?php

namespace App\AI6\Shared\Process;

use App\AI6\Shared\Redaction\Redactor;

final readonly class ExecutionMailboxFactory
{
    public function __construct(private Redactor $redactor) {}

    public function forRole(ExecutionRole $role): ExecutionMailbox
    {
        $root = config('ai6.execution_mailboxes.'.$role->value.'_root');
        $outputRoot = config('ai6.execution_mailboxes.'.$role->value.'_output_root');
        $version = config('ai6.execution_mailboxes.version');
        $maximum = config('ai6.execution_mailboxes.max_envelope_bytes');
        if (! is_string($root) || ! is_string($outputRoot) || (! is_int($version) && ! ctype_digit((string) $version)) || (! is_int($maximum) && ! ctype_digit((string) $maximum))) {
            throw new \InvalidArgumentException('The execution mailbox configuration is invalid.');
        }

        return new ExecutionMailbox($role, $root, (int) $version, (int) $maximum, $this->redactor, $outputRoot);
    }
}
