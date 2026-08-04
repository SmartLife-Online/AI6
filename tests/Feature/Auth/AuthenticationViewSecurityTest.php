<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;

final class AuthenticationViewSecurityTest extends AuthFeatureTestCase
{
    public function test_rendered_authentication_views_use_only_the_self_hosted_external_script(): void
    {
        $views = [
            'auth.login' => [],
            'auth.primary-factor' => [
                'hasPasskey' => true,
                'hasTotp' => true,
                'hasRecoveryCodes' => true,
            ],
            'auth.login-confirmation' => ['confirmation' => null],
            'auth.enroll-totp' => ['secret' => 'JBSWY3DPEHPK3PXP'],
            'auth.enroll-passkey' => [],
        ];

        foreach ($views as $view => $data) {
            $data['errors'] = new ViewErrorBag;
            $rendered = view($view, $data)->render();
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $rendered, $view);
            preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $rendered, $scripts, PREG_SET_ORDER);

            foreach ($scripts as $script) {
                self::assertSame('', trim($script[2]), $view.' contains inline script content.');
                self::assertSame(1, preg_match('/\bsrc="([^"]+)"/i', $script[1], $source));
                $parts = parse_url($source[1]);
                self::assertIsArray($parts);
                self::assertSame('localhost', $parts['host'] ?? 'localhost');
                self::assertSame('/assets/ai6-passkey.js', $parts['path'] ?? null);
            }
        }

        $asset = file_get_contents(public_path('assets/ai6-passkey.js'));
        self::assertIsString($asset);
        self::assertStringContainsString('navigator.credentials.create', $asset);
        self::assertStringContainsString('navigator.credentials.get', $asset);
        self::assertDoesNotMatchRegularExpression('/https?:\/\//i', $asset);
    }

    public function test_confirmation_mail_has_no_effective_link_and_no_get_route_completes_authentication(): void
    {
        $renderedMail = view('mail.login-confirmation', [
            'confirmationCode' => '12345678',
            'confirmationRevision' => 7,
        ])->render();
        self::assertStringContainsString('12345678', $renderedMail);
        self::assertStringContainsString('Code-Version 7', $renderedMail);
        self::assertStringContainsString('ersetzt alle früheren Code-Versionen', $renderedMail);
        self::assertDoesNotMatchRegularExpression('/https?:\/\//i', $renderedMail);
        self::assertDoesNotMatchRegularExpression('/<a\b/i', $renderedMail);

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            self::assertNotContains($route->getName(), [
                'auth.confirmation.verify',
                'auth.primary.totp.verify',
                'auth.primary.recovery.verify',
                'auth.primary.passkey.verify',
            ]);
        }
    }
}
