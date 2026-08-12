<?php

namespace Tests\Feature\Shared\Http;

use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

final class PublicRouteInventoryTest extends TestCase
{
    /**
     * The complete public HTTP surface, pinned exactly: every route with its
     * methods and registered middleware. A dependency or framework update that
     * registers any additional endpoint — or drops middleware from an existing
     * one — must fail here and become a conscious decision. The APP_KEY-derived
     * Livewire prefix is normalized to {livewire}; all package endpoints under
     * it except the CSRF-protected update endpoint are neutralized to 404 by
     * AI6ServiceProvider.
     */
    public function test_the_public_route_inventory_is_exactly_pinned(): void
    {
        $prefix = ltrim(EndpointResolver::prefix(), '/');
        $actual = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
            $uri = '/'.ltrim(str_replace($prefix, '{livewire}', $route->uri()), '/');
            $middleware = array_map(
                static fn ($entry): string => is_string($entry) ? $entry : 'closure',
                $route->middleware(),
            );
            $actual[] = sprintf('%s %s [%s]', $methods, $uri, implode(', ', $middleware));
        }

        sort($actual, SORT_STRING);

        self::assertSame([
            'DELETE /admin/users/{user} [web, auth, can:delete,user]',
            'DELETE /admin/users/{user}/global-administrator [web, auth, can:revokeGlobalAdministrator,user]',
            'DELETE /admin/users/{user}/memberships/{project} [web, auth, can:removeMembership,user]',
            'DELETE /admin/users/{user}/sessions/{session} [web, auth, can:revokeSession,user]',
            'GET / [web]',
            'GET /assets/livewire/livewire.js []',
            'GET /auth/confirmation [web, auth]',
            'GET /auth/enrollment/passkey [web, auth]',
            'GET /auth/enrollment/totp [web, auth]',
            'GET /auth/factor [web, auth]',
            'GET /health []',
            'GET /login [web, guest]',
            'GET /projects [web, auth]',
            'GET /projects/create [web, auth, can:create,App\AI6\Projects\Models\Project]',
            'GET /projects/{project} [web, auth, can:view,project]',
            'GET /projects/{project}/operations/{operation} [web, auth, can:view,project]',
            'GET /projects/{project}/tickets [web, auth, can:view,project]',
            'GET /projects/{project}/tickets/{readModel} [web, auth, can:view,project]',
            'GET /projects/{project}/tickets/{readModel}/edit [web, auth, can:editTicket,project]',
            'GET /storage/{path} []',
            'GET /{livewire}/css/{component}.css []',
            'GET /{livewire}/css/{component}.global.css []',
            'GET /{livewire}/js/{component}.js []',
            'GET /{livewire}/livewire.csp.min.js.map []',
            'GET /{livewire}/livewire.js []',
            'GET /{livewire}/livewire.min.js []',
            'GET /{livewire}/livewire.min.js.map []',
            'GET /{livewire}/preview-file/{filename} []',
            'PATCH /admin/users/{user}/deactivate [web, auth, can:deactivate,user]',
            'POST /admin/users [web, auth, can:create,App\AI6\Auth\Models\User]',
            'POST /auth/confirmation [web, auth]',
            'POST /auth/confirmation/resend [web, auth]',
            'POST /auth/enrollment/passkey [web, auth]',
            'POST /auth/enrollment/passkey/options [web, auth]',
            'POST /auth/enrollment/totp [web, auth]',
            'POST /auth/factor/passkey/options [web, auth]',
            'POST /auth/factor/passkey/verify [web, auth]',
            'POST /auth/factor/recovery [web, auth]',
            'POST /auth/factor/totp [web, auth]',
            'POST /auth/step-up/{action}/passkey [web, auth]',
            'POST /auth/step-up/{action}/passkey/options [web, auth]',
            'POST /auth/step-up/{action}/totp [web, auth]',
            'POST /login [web, guest]',
            'POST /logout [web, auth]',
            'POST /projects [web, auth, can:create,App\AI6\Projects\Models\Project]',
            'POST /projects/{project}/control-branch [web, auth, can:changeControlBranch,project]',
            'POST /projects/{project}/deploy-key [web, auth, can:provisionDeployKey,project]',
            'POST /projects/{project}/managed-clone [web, auth, can:synchronizeManagedClone,project]',
            'POST /projects/{project}/managed-fetch [web, auth, can:synchronizeManagedClone,project]',
            'POST /projects/{project}/operations/{operation}/recovery [web, auth, can:decideRecovery,project]',
            'POST /projects/{project}/ticket-read-model [web, auth, can:refreshReadModel,project]',
            'POST /projects/{project}/tickets/{readModel}/edit [web, auth, can:editTicket,project]',
            'POST /projects/{project}/tickets/{readModel}/status [web, auth, can:changeTicketStatus,project]',
            'POST /{livewire}/update [web, Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders]',
            'POST /{livewire}/upload-file []',
            'PUT /admin/users/{user}/global-administrator [web, auth, can:grantGlobalAdministrator,user]',
            'PUT /admin/users/{user}/memberships/{project} [web, auth, can:setMembership,user]',
            'PUT /storage/{path} []',
        ], $actual);
    }

    public function test_every_neutralized_livewire_endpoint_is_registered_by_the_ai6_provider(): void
    {
        $neutralizedUris = [
            ltrim(EndpointResolver::scriptPath(minified: false), '/'),
            ltrim(EndpointResolver::scriptPath(minified: true), '/'),
            ltrim(EndpointResolver::mapPath(csp: false), '/'),
            ltrim(EndpointResolver::mapPath(csp: true), '/'),
            ltrim(EndpointResolver::componentJsPath(), '/'),
            ltrim(EndpointResolver::componentCssPath(), '/'),
            ltrim(EndpointResolver::componentGlobalCssPath(), '/'),
            ltrim(EndpointResolver::previewPath(), '/'),
            ltrim(EndpointResolver::uploadPath(), '/'),
        ];

        $covered = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array($route->uri(), $neutralizedUris, true)) {
                continue;
            }

            $action = $route->getAction('uses');
            self::assertInstanceOf(
                \Closure::class,
                $action,
                $route->uri().' must resolve to the neutralizing AI6 closure, not a package handler.',
            );
            $covered[] = $route->uri();
        }

        self::assertEqualsCanonicalizing($neutralizedUris, $covered);
    }
}
