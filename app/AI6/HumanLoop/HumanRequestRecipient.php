<?php

namespace App\AI6\HumanLoop;

use App\AI6\Auth\Models\User;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\Policies\ProjectPolicy;
use App\AI6\Projects\ProjectAction;

/**
 * The single server-side resolution of a human request's notified address.
 *
 * Creation and delivery must agree on who is responsible, so the rule lives
 * here once instead of in the service and the job. The address never comes from
 * ticket, project configuration or provider text: it is the account address of
 * the bound attention user, who must be active, a member of the project and
 * actually permitted to answer — a recipient without that permission would be
 * pointed at a page they cannot open.
 */
final readonly class HumanRequestRecipient
{
    public function __construct(private ProjectPolicy $policy) {}

    public function resolve(?int $attentionUserId, int $projectId): ?string
    {
        if ($attentionUserId === null) {
            return null;
        }

        $user = User::query()->whereKey($attentionUserId)->where('is_active', true)->first();
        if (! $user instanceof User || $user->email === '') {
            return null;
        }

        $membership = ProjectMembership::query()
            ->where('user_id', $user->getKey())
            ->where('project_id', $projectId)
            ->first();
        if (! $membership instanceof ProjectMembership
            || ! $this->policy->decisionFor(ProjectAction::ANSWER_HUMAN_REQUEST, $membership->role)) {
            return null;
        }

        return $user->email;
    }
}
