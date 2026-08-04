<?php

namespace App\AI6\Shared\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

final readonly class HttpSecurityConfiguration
{
    /**
     * @param  non-empty-list<string>  $trustedHosts
     * @param  list<string>  $trustedProxies
     * @param  array<string, string>  $contentSecurityPolicyDirectives
     */
    public function __construct(
        public array $trustedHosts,
        public array $trustedProxies,
        public string $sessionSameSite,
        public string $scriptAssetPath,
        public array $contentSecurityPolicyDirectives,
    ) {}

    public function trustsHost(string $host): bool
    {
        return in_array(strtolower($host), $this->trustedHosts, true);
    }

    public function trustsProxy(string $address): bool
    {
        foreach ($this->trustedProxies as $trustedProxy) {
            if (IpUtils::checkIp($address, $trustedProxy)) {
                return true;
            }
        }

        return false;
    }

    public function contentSecurityPolicy(Request $request): string
    {
        $directives = [];

        foreach ($this->contentSecurityPolicyDirectives as $name => $value) {
            if ($name === 'script-src') {
                $value = $request->getSchemeAndHttpHost().$this->scriptAssetPath;
            }

            $directives[] = $name.' '.$value;
        }

        return implode('; ', $directives).';';
    }
}
