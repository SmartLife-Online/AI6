<?php

namespace App\AI6\Shared\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveTrustedProxies
{
    private const TRUSTED_HEADER_SET = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT;

    /** @var list<string> */
    private const ACCEPTED_FORWARDING_HEADERS = [
        'X-Forwarded-For',
        'X-Forwarded-Host',
        'X-Forwarded-Proto',
        'X-Forwarded-Port',
    ];

    public function __construct(private HttpSecurityConfiguration $configuration) {}

    public function handle(Request $request, Closure $next): Response
    {
        Request::setTrustedProxies([], self::TRUSTED_HEADER_SET);

        $peer = $request->server->get('REMOTE_ADDR');

        if (! is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false) {
            return $this->reject();
        }

        $peerIsTrusted = $this->configuration->trustsProxy($peer);
        Request::setTrustedProxies($this->configuration->trustedProxies, self::TRUSTED_HEADER_SET);

        if ((! $peerIsTrusted && $this->hasForwardingHeader($request))
            || ($peerIsTrusted && ! $this->hasValidForwardingHeaders($request))) {
            return $this->reject();
        }

        $directHost = $request->headers->get('Host');
        $directHost = is_string($directHost) ? $this->hostWithoutPort($directHost) : null;

        if ($directHost === null || ! $this->configuration->trustsHost($directHost)) {
            return $this->reject();
        }

        try {
            $host = $request->getHost();
        } catch (SuspiciousOperationException) {
            return $this->reject();
        }

        if (! $this->configuration->trustsHost($host)) {
            return $this->reject();
        }

        return $next($request);
    }

    private function hasForwardingHeader(Request $request): bool
    {
        foreach (array_keys($request->headers->all()) as $header) {
            if ($header === 'forwarded' || str_starts_with($header, 'x-forwarded-')) {
                return true;
            }
        }

        return false;
    }

    private function hasValidForwardingHeaders(Request $request): bool
    {
        foreach (array_keys($request->headers->all()) as $header) {
            if ($header === 'forwarded') {
                return false;
            }

            if (str_starts_with($header, 'x-forwarded-')
                && ! in_array(strtolower($header), array_map(strtolower(...), self::ACCEPTED_FORWARDING_HEADERS), true)) {
                return false;
            }
        }

        $forwardedFor = $request->headers->get('X-Forwarded-For');

        if ($forwardedFor !== null) {
            foreach (explode(',', $forwardedFor) as $address) {
                if (filter_var(trim($address), FILTER_VALIDATE_IP) === false) {
                    return false;
                }
            }
        }

        $forwardedHost = $request->headers->get('X-Forwarded-Host');

        if ($forwardedHost !== null) {
            foreach (explode(',', $forwardedHost) as $host) {
                $host = $this->hostWithoutPort($host);

                if ($host === null || ! $this->configuration->trustsHost($host)) {
                    return false;
                }
            }
        }

        $forwardedProto = $request->headers->get('X-Forwarded-Proto');

        if ($forwardedProto !== null) {
            foreach (explode(',', $forwardedProto) as $protocol) {
                if (! in_array(strtolower(trim($protocol)), ['http', 'https'], true)) {
                    return false;
                }
            }
        }

        $forwardedPort = $request->headers->get('X-Forwarded-Port');

        if ($forwardedPort !== null) {
            foreach (explode(',', $forwardedPort) as $port) {
                $port = trim($port);

                if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hostWithoutPort(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/[\x00-\x20\x7F,]/', $value) === 1) {
            return null;
        }

        if (preg_match('/\A\[([^]]+)](?::([0-9]{1,5}))?\z/D', $value, $matches) === 1) {
            if (isset($matches[2]) && (int) $matches[2] > 65535) {
                return null;
            }

            return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? strtolower($matches[1])
                : null;
        }

        if (substr_count($value, ':') > 1
            || preg_match('/\A([^:]+)(?::([0-9]{1,5}))?\z/D', $value, $matches) !== 1
            || (isset($matches[2]) && ((int) $matches[2] < 1 || (int) $matches[2] > 65535))) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function reject(): Response
    {
        return response('Ungültige Anfrage.', 400)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
