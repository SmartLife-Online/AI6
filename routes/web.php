<?php

use App\AI6\Auth\Http\AdministrativeController;
use App\AI6\Auth\Http\LoginController;
use App\AI6\Auth\Models\User;
use App\AI6\Projects\Http\ProjectController;
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

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])
        ->middleware('can:view,project')
        ->name('projects.show');

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
