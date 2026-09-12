<?php

namespace Tests\Feature\Agents;

/** Exercise the shipped alias through the mailbox and review consumer for every answer outcome. */
final class ClaudeCopilotCliExecutionTest extends GitHubCopilotCliExecutionTest
{
    protected string $copilotProfile = 'copilot-claude-sonnet-review';

    protected string $copilotModel = 'claude-sonnet-4.6';
}
