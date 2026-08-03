<?php

namespace App\AI6\Auth\Http;

use App\AI6\Auth\Actions\CreateUser;
use App\AI6\Auth\Actions\DeactivateUser;
use App\AI6\Auth\Actions\DeleteUser;
use App\AI6\Auth\Actions\GrantGlobalAdministrator;
use App\AI6\Auth\Actions\RemoveProjectMembership;
use App\AI6\Auth\Actions\RevokeGlobalAdministrator;
use App\AI6\Auth\Actions\RevokeUserSession;
use App\AI6\Auth\Actions\SetProjectMembership;
use App\AI6\Auth\EmailNormalizer;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\Models\UserSession;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\ProjectRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class AdministrativeController
{
    public function createUser(
        Request $request,
        CreateUser $action,
        EmailNormalizer $normalizer,
    ): JsonResponse {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => $normalizer->normalize($email)]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);
        $user = $action->handle($validated['name'], $validated['email'], $validated['password']);

        return response()->json([
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ], 201);
    }

    public function deactivateUser(User $user, DeactivateUser $action): Response
    {
        $action->handle($user);

        return response()->noContent();
    }

    public function deleteUser(User $user, DeleteUser $action): Response
    {
        $action->handle($user);

        return response()->noContent();
    }

    public function grantGlobalAdministrator(User $user, GrantGlobalAdministrator $action): Response
    {
        $action->handle($user);

        return response()->noContent();
    }

    public function revokeGlobalAdministrator(User $user, RevokeGlobalAdministrator $action): Response
    {
        $action->handle($user);

        return response()->noContent();
    }

    public function setMembership(
        Request $request,
        User $user,
        Project $project,
        SetProjectMembership $action,
    ): Response {
        $validated = $request->validate([
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ]);
        $action->handle($user, $project, ProjectRole::from($validated['role']));

        return response()->noContent();
    }

    public function removeMembership(
        User $user,
        Project $project,
        RemoveProjectMembership $action,
    ): Response {
        $action->handle($user, $project);

        return response()->noContent();
    }

    public function revokeSession(
        User $user,
        string $session,
        RevokeUserSession $action,
    ): Response {
        $userSession = UserSession::query()
            ->whereKey($session)
            ->where('user_id', $user->getKey())
            ->firstOrFail();
        $action->handle($userSession);

        return response()->noContent();
    }
}
