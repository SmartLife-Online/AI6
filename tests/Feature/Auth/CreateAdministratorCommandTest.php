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
                if (! is_file($logFile)) {
                    continue;
                }

                self::assertFalse(
                    $this->fileContains($logFile, $password),
                    sprintf('The log file %s contains the password.', basename($logFile)),
                );
            }
        } finally {
            if (is_string($previous)) {
                putenv($environmentKey.'='.$previous);
            } else {
                putenv($environmentKey);
            }
        }
    }

    /**
     * Scans the complete file in bounded chunks so a grown local log cannot exhaust the memory
     * limit, while an overlap of one byte less than the needle keeps chunk boundaries covered.
     */
    private function fileContains(string $path, string $needle): bool
    {
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        $overlap = max(0, strlen($needle) - 1);
        $carry = '';

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if (! is_string($chunk) || $chunk === '') {
                    break;
                }
                $window = $carry.$chunk;
                if (str_contains($window, $needle)) {
                    return true;
                }
                $carry = $overlap === 0 ? '' : substr($window, -$overlap);
            }
        } finally {
            fclose($handle);
        }

        return false;
    }
}
