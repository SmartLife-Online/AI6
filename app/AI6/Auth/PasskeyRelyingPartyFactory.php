<?php

namespace App\AI6\Auth;

use App\AI6\Shared\Config\ConfigurationException;

final class PasskeyRelyingPartyFactory
{
    public function fromConfiguredValues(): PasskeyRelyingParty
    {
        $url = config('app.url');
        $name = config('app.name');

        if (! is_string($url) || ! is_string($name) || trim($name) === '') {
            throw new ConfigurationException('Configuration keys APP_URL and APP_NAME must define the WebAuthn relying party.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            throw new ConfigurationException('Configuration key APP_URL must be an absolute WebAuthn origin.');
        }

        $scheme = strtolower($scheme);
        $host = strtolower(rtrim($host, '.'));
        $isHttpLocalhost = $scheme === 'http' && $host === 'localhost';

        if ($scheme !== 'https' && ! $isHttpLocalhost) {
            throw new ConfigurationException('Configuration key APP_URL must use HTTPS or the HTTP localhost origin supported by the WebAuthn verifier.');
        }

        $originHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $origin = $scheme.'://'.$originHost.(is_int($port) ? ':'.$port : '');

        return new PasskeyRelyingParty(trim($name), $host, $origin);
    }
}
