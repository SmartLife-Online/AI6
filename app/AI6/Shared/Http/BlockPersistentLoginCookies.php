<?php

namespace App\AI6\Shared\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BlockPersistentLoginCookies
{
    private const REMEMBER_COOKIE_PREFIX = 'remember_';

    public function handle(Request $request, Closure $next): Response
    {
        foreach (array_keys($request->cookies->all()) as $cookieName) {
            if (str_starts_with($cookieName, self::REMEMBER_COOKIE_PREFIX)) {
                $request->cookies->remove($cookieName);
            }
        }

        $response = $next($request);

        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), self::REMEMBER_COOKIE_PREFIX)) {
                $response->headers->removeCookie(
                    $cookie->getName(),
                    $cookie->getPath(),
                    $cookie->getDomain(),
                );
            }
        }

        return $response;
    }
}
