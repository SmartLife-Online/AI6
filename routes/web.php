<?php

use App\AI6\Auth\Http\AdministrativeController;
use App\AI6\Auth\Http\EnrollmentController;
use App\AI6\Auth\Http\LoginConfirmationController;
use App\AI6\Auth\Http\LoginController;
use App\AI6\Auth\Http\PrimaryAuthenticationController;
use App\AI6\Auth\Http\StepUpController;
use App\AI6\Auth\Models\User;
use App\AI6\Git\Http\ControlOperationController;
use App\AI6\Projects\Http\ProjectController;
use App\AI6\Projects\Models\Project;
use App\AI6\Tickets\Livewire\TicketDetail;
use App\AI6\Tickets\Livewire\TicketList;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => Auth::check()
    ? redirect()->route('projects.index')
    : redirect()->route('login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('/factor', [PrimaryAuthenticationController::class, 'factor'])
            ->name('primary.factor');
        Route::post('/factor/totp', [PrimaryAuthenticationController::class, 'verifyTotp'])
            ->name('primary.totp.verify');
        Route::post('/factor/recovery', [PrimaryAuthenticationController::class, 'verifyRecovery'])
            ->name('primary.recovery.verify');
        Route::post('/factor/passkey/options', [PrimaryAuthenticationController::class, 'passkeyOptions'])
            ->name('primary.passkey.options');
        Route::post('/factor/passkey/verify', [PrimaryAuthenticationController::class, 'verifyPasskey'])
            ->name('primary.passkey.verify');

        Route::get('/confirmation', [LoginConfirmationController::class, 'show'])
            ->name('confirmation.show');
        Route::post('/confirmation', [LoginConfirmationController::class, 'verify'])
            ->name('confirmation.verify');
        Route::post('/confirmation/resend', [LoginConfirmationController::class, 'resend'])
            ->name('confirmation.resend');

        Route::get('/enrollment/totp', [EnrollmentController::class, 'showTotp'])
            ->name('enrollment.totp.show');
        Route::post('/enrollment/totp', [EnrollmentController::class, 'confirmTotp'])
            ->name('enrollment.totp.confirm');
        Route::get('/enrollment/passkey', [EnrollmentController::class, 'showPasskey'])
            ->name('enrollment.passkey.show');
        Route::post('/enrollment/passkey/options', [EnrollmentController::class, 'passkeyOptions'])
            ->name('enrollment.passkey.options');
        Route::post('/enrollment/passkey', [EnrollmentController::class, 'registerPasskey'])
            ->name('enrollment.passkey.register');

        Route::post('/step-up/{action}/totp', [StepUpController::class, 'verifyTotp'])
            ->where('action', '[a-z][a-z0-9._-]{0,63}')
            ->name('step-up.totp.verify');
        Route::post('/step-up/{action}/passkey/options', [StepUpController::class, 'passkeyOptions'])
            ->where('action', '[a-z][a-z0-9._-]{0,63}')
            ->name('step-up.passkey.options');
        Route::post('/step-up/{action}/passkey', [StepUpController::class, 'verifyPasskey'])
            ->where('action', '[a-z][a-z0-9._-]{0,63}')
            ->name('step-up.passkey.verify');
    });

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])
        ->middleware('can:create,'.Project::class)
        ->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])
        ->middleware('can:create,'.Project::class)
        ->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])
        ->middleware('can:view,project')
        ->name('projects.show');
    Route::get('/projects/{project}/tickets', TicketList::class)
        ->middleware('can:view,project')
        ->name('projects.tickets.index');
    Route::get('/projects/{project}/tickets/{readModel}', TicketDetail::class)
        ->middleware('can:view,project')
        ->name('projects.tickets.show');
    Route::post('/projects/{project}/deploy-key', [ControlOperationController::class, 'provision'])
        ->middleware('can:provisionDeployKey,project')
        ->name('projects.deploy-key.provision');
    Route::post('/projects/{project}/managed-clone', [ControlOperationController::class, 'clone'])
        ->middleware('can:synchronizeManagedClone,project')
        ->name('projects.managed-clone.clone');
    Route::post('/projects/{project}/managed-fetch', [ControlOperationController::class, 'fetch'])
        ->middleware('can:synchronizeManagedClone,project')
        ->name('projects.managed-clone.fetch');
    Route::post('/projects/{project}/control-branch', [ControlOperationController::class, 'changeControlBranch'])
        ->middleware('can:changeControlBranch,project')
        ->name('projects.control-branch.change');
    Route::post('/projects/{project}/ticket-read-model', [ControlOperationController::class, 'refreshReadModel'])
        ->middleware('can:refreshReadModel,project')
        ->name('projects.ticket-read-model.refresh');
    Route::get('/projects/{project}/operations/{operation}', [ControlOperationController::class, 'show'])
        ->middleware('can:view,project')
        ->name('projects.operations.show');
    Route::post('/projects/{project}/operations/{operation}/recovery', [ControlOperationController::class, 'recover'])
        ->middleware('can:decideRecovery,project')
        ->name('projects.operations.recover');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::post('/users', [AdministrativeController::class, 'createUser'])
            ->middleware('can:create,'.User::class)
            ->name('users.create');
        Route::patch('/users/{user}/deactivate', [AdministrativeController::class, 'deactivateUser'])
            ->middleware('can:deactivate,user')
            ->name('users.deactivate');
        Route::delete('/users/{user}', [AdministrativeController::class, 'deleteUser'])
            ->middleware('can:delete,user')
            ->name('users.delete');
        Route::put('/users/{user}/global-administrator', [AdministrativeController::class, 'grantGlobalAdministrator'])
            ->middleware('can:grantGlobalAdministrator,user')
            ->name('users.global-administrator.grant');
        Route::delete('/users/{user}/global-administrator', [AdministrativeController::class, 'revokeGlobalAdministrator'])
            ->middleware('can:revokeGlobalAdministrator,user')
            ->name('users.global-administrator.revoke');
        Route::put('/users/{user}/memberships/{project}', [AdministrativeController::class, 'setMembership'])
            ->middleware('can:setMembership,user')
            ->name('users.memberships.set');
        Route::delete('/users/{user}/memberships/{project}', [AdministrativeController::class, 'removeMembership'])
            ->middleware('can:removeMembership,user')
            ->name('users.memberships.remove');
        Route::delete('/users/{user}/sessions/{session}', [AdministrativeController::class, 'revokeSession'])
            ->middleware('can:revokeSession,user')
            ->name('users.sessions.revoke');
    });
});
