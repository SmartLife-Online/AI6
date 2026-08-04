<?php

namespace App\AI6\Shared\Http;

use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceHttpsOrPrivateAccess
{
    public function __construct(
        private SecurityPolicy $securityPolicy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secureRequest = $request->isSecure();

        if (! $secureRequest && ! $this->usesPrivateAccess($request)) {
            return response('Unsichere Anfrage abgelehnt.', 400)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        config([
            'session.secure' => $secureRequest || $this->securityPolicy->isEnabled(
                SecurityMeasure::REQUIRE_HTTPS_OR_PRIVATE_ACCESS,
            ),
        ]);

        return $next($request);
    }

    private function usesPrivateAccess(Request $request): bool
    {
        $client = $request->getClientIp();

        return is_string($client) && $this->isLoopback($client);
    }

    private function isLoopback(string $address): bool
    {
        return IpUtils::checkIp($address, ['127.0.0.0/8', '::1']);
    }
}
