<?php

namespace App\AI6\Shared\Http;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictEnumParser;

final class HttpSecurityConfigurationFactory
{
    /** @var array<string, string> */
    private const REQUIRED_CSP_DIRECTIVES = [
        'default-src' => "'self'",
        'script-src' => '{asset-origin}',
        'style-src' => "'self'",
        'img-src' => "'self'",
        'font-src' => "'self'",
        'connect-src' => "'self'",
        'form-action' => "'self'",
        'base-uri' => "'none'",
        'object-src' => "'none'",
        'frame-ancestors' => "'none'",
    ];

    public function __construct(
        private readonly StrictEnumParser $enumParser = new StrictEnumParser,
    ) {}

    public function fromConfiguredValues(): HttpSecurityConfiguration
    {
        $result = $this->inspect(config('ai6.http_hardening'));

        if ($result instanceof ConfigurationViolation) {
            throw new ConfigurationException($result->message);
        }

        return $result;
    }

    public function inspect(mixed $configuration): HttpSecurityConfiguration|ConfigurationViolation
    {
        if (! is_array($configuration)) {
            return new ConfigurationViolation('Configuration key ai6.http_hardening must be an array.');
        }

        $trustedHosts = $this->parseHosts($configuration['trusted_hosts'] ?? null);

        if ($trustedHosts instanceof ConfigurationViolation) {
            return $trustedHosts;
        }

        $trustedProxies = $this->parseProxies($configuration['trusted_proxies'] ?? null);

        if ($trustedProxies instanceof ConfigurationViolation) {
            return $trustedProxies;
        }

        $sameSite = $this->enumParser->parse(
            'AI6_HTTP_SESSION_SAME_SITE',
            $configuration['session_same_site'] ?? null,
            ['lax', 'strict'],
        );

        if ($sameSite instanceof ConfigurationViolation) {
            return $sameSite;
        }

        $scriptAssetPath = $configuration['script_asset_path'] ?? null;

        if (! is_string($scriptAssetPath)
            || preg_match('#\A/[A-Za-z0-9/_-]+/\z#D', $scriptAssetPath) !== 1
            || str_contains($scriptAssetPath, '//')) {
            return new ConfigurationViolation(
                'Configuration key ai6.http_hardening.script_asset_path must be an absolute, trailing-slash asset path.',
            );
        }

        $directives = $this->parseContentSecurityPolicyDirectives(
            $configuration['csp_directives'] ?? null,
        );

        if ($directives instanceof ConfigurationViolation) {
            return $directives;
        }

        return new HttpSecurityConfiguration(
            $trustedHosts,
            $trustedProxies,
            $sameSite,
            $scriptAssetPath,
            $directives,
        );
    }

    /** @return non-empty-list<string>|ConfigurationViolation */
    private function parseHosts(mixed $value): array|ConfigurationViolation
    {
        $hosts = $this->parseCommaSeparatedList('AI6_HTTP_TRUSTED_HOSTS', $value, false);

        if ($hosts instanceof ConfigurationViolation) {
            return $hosts;
        }

        $normalized = [];

        foreach ($hosts as $host) {
            $host = strtolower($host);

            if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
                $host = substr($host, 1, -1);
            }

            if (filter_var($host, FILTER_VALIDATE_IP) === false && ! $this->isValidHostname($host)) {
                return new ConfigurationViolation(
                    'Configuration key AI6_HTTP_TRUSTED_HOSTS must contain only exact host names or IP addresses.',
                );
            }

            $normalized[] = $host;
        }

        /** @var non-empty-list<string> $normalized */
        return array_values(array_unique($normalized));
    }

    /** @return list<string>|ConfigurationViolation */
    private function parseProxies(mixed $value): array|ConfigurationViolation
    {
        $proxies = $this->parseCommaSeparatedList('AI6_HTTP_TRUSTED_PROXIES', $value, true);

        if ($proxies instanceof ConfigurationViolation) {
            return $proxies;
        }

        foreach ($proxies as $proxy) {
            if (! $this->isValidIpOrCidr($proxy)) {
                return new ConfigurationViolation(
                    'Configuration key AI6_HTTP_TRUSTED_PROXIES must contain only explicit IP addresses or CIDR ranges.',
                );
            }
        }

        return array_values(array_unique($proxies));
    }

    /**
     * @return list<string>|ConfigurationViolation
     */
    private function parseCommaSeparatedList(
        string $key,
        mixed $value,
        bool $emptyAllowed,
    ): array|ConfigurationViolation {
        if (! is_string($value)) {
            return new ConfigurationViolation(sprintf('Configuration key %s must be a comma-separated string.', $key));
        }

        if ($value === '') {
            return $emptyAllowed
                ? []
                : new ConfigurationViolation(sprintf('Configuration key %s must not be empty.', $key));
        }

        $parts = explode(',', $value);
        $result = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                return new ConfigurationViolation(sprintf(
                    'Configuration key %s must not contain empty list entries.',
                    $key,
                ));
            }

            $result[] = $part;
        }

        return $result;
    }

    private function isValidHostname(string $host): bool
    {
        return preg_match(
            '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/D',
            $host,
        ) === 1;
    }

    private function isValidIpOrCidr(string $value): bool
    {
        if (! str_contains($value, '/')) {
            return filter_var($value, FILTER_VALIDATE_IP) !== false;
        }

        if (substr_count($value, '/') !== 1) {
            return false;
        }

        [$address, $prefix] = explode('/', $value, 2);

        if (! ctype_digit($prefix)) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (int) $prefix >= 1 && (int) $prefix <= 32;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return (int) $prefix >= 1 && (int) $prefix <= 128;
        }

        return false;
    }

    /** @return array<string, string>|ConfigurationViolation */
    private function parseContentSecurityPolicyDirectives(mixed $value): array|ConfigurationViolation
    {
        if (! is_array($value) || array_keys($value) !== array_keys(self::REQUIRED_CSP_DIRECTIVES)) {
            return new ConfigurationViolation(
                'Configuration key ai6.http_hardening.csp_directives must contain exactly the required directives in canonical order.',
            );
        }

        foreach (self::REQUIRED_CSP_DIRECTIVES as $directive => $requiredValue) {
            if (($value[$directive] ?? null) !== $requiredValue) {
                return new ConfigurationViolation(sprintf(
                    'Configuration key ai6.http_hardening.csp_directives.%s must use the fixed safe baseline.',
                    $directive,
                ));
            }
        }

        return self::REQUIRED_CSP_DIRECTIVES;
    }
}
