<?php

namespace App\AI6\Shared\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ContentSecurityPolicy
{
    private const FAILURE_POLICY = "default-src 'none'; base-uri 'none'; form-action 'none'; "
        ."object-src 'none'; frame-ancestors 'none';";

    public function __construct(private HttpSecurityConfiguration $configuration) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $this->apply($next($request), $request);
    }

    public function apply(Response $response, Request $request): Response
    {
        $contentType = strtolower((string) $response->headers->get('Content-Type'));

        if (str_contains($contentType, 'text/html')) {
            $response->headers->set(
                'Content-Security-Policy',
                $this->configuration->contentSecurityPolicy($request),
            );
        }

        return $response;
    }

    public static function applyFailurePolicy(Response $response): Response
    {
        $contentType = strtolower((string) $response->headers->get('Content-Type'));

        if (str_contains($contentType, 'text/html')) {
            $response->headers->set('Content-Security-Policy', self::FAILURE_POLICY);
        }

        return $response;
    }
}
