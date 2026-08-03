<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

final class CreateAdministratorCommandTest extends AuthFeatureTestCase
{
    public function test_command_bootstraps_once_without_exposing_the_password(): void
    {
        $password = bin2hex(random_bytes(16));
        $environmentKey = 'AI6_TEST_CREATE_ADMIN_PASSWORD';
        $previous = getenv($environmentKey);
        putenv($environmentKey.'='.$password);

        try {
            $firstExitCode = Artisan::call('ai6:create-admin', [
                'email' => ' First.Admin@Example.Test ',
                '--name' => 'Erste Administration',
                '--password-env' => $environmentKey,
            ]);
            $firstOutput = Artisan::output();

            self::assertSame(0, $firstExitCode, $firstOutput);
            self::assertStringNotContainsString($password, $firstOutput);
            self::assertDatabaseCount('users', 1);
            $user = User::query()->sole();
            self::assertSame('first.admin@example.test', $user->email);
            self::assertTrue($user->is_active);
            self::assertTrue($user->is_global_admin);
            self::assertTrue(Hash::check($password, $user->password));

            $secondExitCode = Artisan::call('ai6:create-admin', [
                'email' => 'first.admin@example.test',
                '--name' => 'Andere Administration',
                '--password-env' => $environmentKey,
            ]);
            $secondOutput = Artisan::output();

            self::assertNotSame(0, $secondExitCode);
            self::assertDatabaseCount('users', 1);
            self::assertStringNotContainsString($password, $secondOutput);

            foreach (glob(storage_path('logs/*')) ?: [] as $logFile) {
                $contents = file_get_contents($logFile);
                self::assertIsString($contents);
                self::assertStringNotContainsString($password, $contents);
            }
        } finally {
            if (is_string($previous)) {
                putenv($environmentKey.'='.$previous);
            } else {
                putenv($environmentKey);
            }
        }
    }
}
