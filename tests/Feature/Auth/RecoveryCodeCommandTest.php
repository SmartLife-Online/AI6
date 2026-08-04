<?php

namespace Tests\Feature\Auth;

use App\AI6\Auth\Console\ReissueRecoveryCodesCommand;
use App\AI6\Auth\Models\AuthenticationAuditEntry;
use App\AI6\Auth\Models\RecoveryCode;
use App\AI6\Auth\RecoveryCodeManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class RecoveryCodeCommandTest extends AuthFeatureTestCase
{
    public function test_command_outputs_one_new_hashed_set_and_each_code_is_single_use(): void
    {
        config(['hashing.bcrypt.rounds' => 4]);
        $user = $this->createUser(['email' => 'recovery@example.test']);
        $this->createPasskey($user);

        self::assertSame(0, Artisan::call('ai6:reissue-recovery-codes', ['email' => $user->email]));
        $firstOutput = Artisan::output();
        $firstCodes = $this->codesFromOutput($firstOutput);
        self::assertCount(RecoveryCodeManager::CODE_COUNT, $firstCodes);
        self::assertCount(RecoveryCodeManager::CODE_COUNT, array_unique($firstCodes));

        $stored = RecoveryCode::query()->get();
        self::assertCount(RecoveryCodeManager::CODE_COUNT, $stored);
        foreach ($firstCodes as $code) {
            self::assertFalse($stored->contains(
                static fn (RecoveryCode $row): bool => hash_equals($row->code_hash, $code),
            ));
            self::assertTrue($stored->contains(
                static fn (RecoveryCode $row): bool => Hash::check($code, $row->code_hash),
            ));
        }

        $manager = $this->app->make(RecoveryCodeManager::class);
        self::assertTrue($manager->consume($user, $firstCodes[0]));
        self::assertFalse($manager->consume($user, $firstCodes[0]));

        self::assertSame(0, Artisan::call('ai6:reissue-recovery-codes', ['email' => $user->email]));
        $secondCodes = $this->codesFromOutput(Artisan::output());
        self::assertCount(RecoveryCodeManager::CODE_COUNT, $secondCodes);
        self::assertSame([], array_values(array_intersect($firstCodes, $secondCodes)));

        $databaseDump = json_encode([
            RecoveryCode::query()->get()->toArray(),
            AuthenticationAuditEntry::query()->get()->toArray(),
        ], JSON_THROW_ON_ERROR);
        foreach (array_merge($firstCodes, $secondCodes) as $code) {
            self::assertStringNotContainsString($code, $databaseDump);
        }

        $audit = AuthenticationAuditEntry::query()
            ->where('event', 'recovery_codes_reissued')
            ->latest('id')
            ->firstOrFail();
        self::assertSame([
            'count' => RecoveryCodeManager::CODE_COUNT,
            'execution' => 'local_shell',
        ], $audit->context);
        self::assertSame($user->getKey(), $audit->user_id);
    }

    public function test_command_rejects_unknown_inactive_and_factorless_users_without_state_or_code_output(): void
    {
        config(['hashing.bcrypt.rounds' => 4]);
        $inactive = $this->createUser([
            'email' => 'inactive-recovery@example.test',
            'is_active' => false,
        ]);
        $this->createPasskey($inactive, 'inactive-credential');
        $factorless = $this->createUser(['email' => 'factorless@example.test']);

        foreach ([
            'unknown@example.test',
            $inactive->email,
            $factorless->email,
        ] as $email) {
            $before = RecoveryCode::query()->orderBy('id')->get()->toArray();

            self::assertNotSame(0, Artisan::call('ai6:reissue-recovery-codes', ['email' => $email]));
            self::assertSame([], $this->codesFromOutput(Artisan::output()));
            self::assertSame($before, RecoveryCode::query()->orderBy('id')->get()->toArray());
        }

        self::assertDatabaseCount('authentication_audit_entries', 0);
    }

    public function test_failure_between_invalidation_and_output_rolls_the_whole_reissue_back(): void
    {
        config(['hashing.bcrypt.rounds' => 4]);
        $user = $this->createUser();
        $this->createPasskey($user);
        $manager = $this->app->make(RecoveryCodeManager::class);
        $manager->reissue($user);
        $before = RecoveryCode::query()->orderBy('id')->pluck('code_hash', 'id')->all();
        $outputReached = false;
        RecoveryCode::creating(static function (): never {
            throw new RuntimeException('forced-before-output');
        });

        try {
            $manager->reissue($user, static function () use (&$outputReached): void {
                $outputReached = true;
            });
            self::fail('The forced transaction error must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('forced-before-output', $exception->getMessage());
        }

        self::assertFalse($outputReached);
        self::assertSame($before, RecoveryCode::query()->orderBy('id')->pluck('code_hash', 'id')->all());
        self::assertDatabaseCount('recovery_codes', RecoveryCodeManager::CODE_COUNT);
        self::assertDatabaseCount('authentication_audit_entries', 1);
    }

    public function test_recovery_reissue_has_no_http_queue_or_file_output_path(): void
    {
        $routes = array_map(
            static fn ($route): string => implode('|', [
                (string) $route->getName(),
                $route->uri(),
                $route->getActionName(),
            ]),
            Route::getRoutes()->getRoutes(),
        );
        self::assertDoesNotMatchRegularExpression(
            '/reissue.{0,30}recovery|recovery.{0,30}reissue/i',
            implode("\n", $routes),
        );

        $jobSources = '';
        foreach (glob(base_path('app/AI6/Auth/Jobs/*.php')) ?: [] as $file) {
            $jobSources .= file_get_contents($file) ?: '';
        }
        self::assertStringNotContainsString('RecoveryCodeManager', $jobSources);
        self::assertStringNotContainsString('reissue(', $jobSources);

        $command = Artisan::all()['ai6:reissue-recovery-codes'] ?? null;
        self::assertInstanceOf(ReissueRecoveryCodesCommand::class, $command);
        self::assertSame(['email'], array_keys($command->getDefinition()->getArguments()));
        foreach (array_keys($command->getDefinition()->getOptions()) as $option) {
            self::assertDoesNotMatchRegularExpression('/file|path|output|all|user/i', $option);
        }

        $commandSource = file_get_contents(base_path('app/AI6/Auth/Console/ReissueRecoveryCodesCommand.php')) ?: '';
        self::assertStringNotContainsString('StepUp', $commandSource);
        self::assertStringNotContainsString('Log::', $commandSource);
    }

    /** @return list<string> */
    private function codesFromOutput(string $output): array
    {
        preg_match_all(
            '/(?<![A-F0-9-])[A-F0-9]{4}(?:-[A-F0-9]{4}){3}(?![A-F0-9-])/',
            $output,
            $matches,
        );

        return $matches[0];
    }
}
