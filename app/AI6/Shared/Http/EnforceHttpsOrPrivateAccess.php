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
    /**
     * A trusted reverse proxy asserts with this header that the request entered
     * through an ingress that is bound to the host loopback. The proxy never
     * rewrites the client address for it, so auditing keeps the real client.
     */
    public const LOOPBACK_INGRESS_HEADER = 'X-AI6-Ingress';

    public const LOOPBACK_INGRESS_VALUE = 'loopback-publication';

    public function __construct(
        private SecurityPolicy $securityPolicy,
        private HttpSecurityConfiguration $httpConfiguration,
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

        if (is_string($client) && $this->isLoopback($client)) {
            return true;
        }

        return $this->hasAssertedLoopbackIngress($request);
    }

    /**
     * A container runtime presents a connection published on the host loopback
     * to the reverse proxy as its bridge or gateway address. Only the proxy can
     * distinguish that ingress, so it asserts it explicitly; the assertion is
     * honoured exclusively when the immediate peer is a configured trusted
     * proxy, which no untrusted client can become.
     */
    private function hasAssertedLoopbackIngress(Request $request): bool
    {
        $peer = $request->server->get('REMOTE_ADDR');

        if (! is_string($peer)
            || filter_var($peer, FILTER_VALIDATE_IP) === false
            || ! $this->httpConfiguration->trustsProxy($peer)) {
            return false;
        }

        $assertion = $request->headers->get(self::LOOPBACK_INGRESS_HEADER);

        return is_string($assertion) && hash_equals(self::LOOPBACK_INGRESS_VALUE, $assertion);
    }

    private function isLoopback(string $address): bool
    {
        return IpUtils::checkIp($address, ['127.0.0.0/8', '::1']);
    }
}
