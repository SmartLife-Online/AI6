<?php

namespace App\AI6\Auth;

use App\AI6\Auth\Models\RecoveryCode;
use App\AI6\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class RecoveryCodeManager
{
    public const CODE_COUNT = 10;

    public function __construct(
        private StrongFactorInventory $factors,
        private AuthenticationAudit $audit,
    ) {}

    /**
     * @param  (callable(list<string>): void)|null  $beforeCommit
     * @return list<string>
     */
    public function reissue(User $user, ?callable $beforeCommit = null): array
    {
        if (! $user->is_active || ! $this->factors->hasStrongFactor($user)) {
            throw new \DomainException('Recovery codes require an active user with a registered strong factor.');
        }

        return DB::transaction(function () use ($user, $beforeCommit): array {
            RecoveryCode::query()->where('user_id', $user->getKey())->delete();
            $codes = [];

            for ($index = 0; $index < self::CODE_COUNT; $index++) {
                $plain = strtoupper(implode('-', str_split(bin2hex(random_bytes(8)), 4)));
                $codes[] = $plain;
                RecoveryCode::query()->create([
                    'user_id' => $user->getKey(),
                    'code_hash' => Hash::make($plain),
                    'issued_at' => now(),
                ]);
            }

            $this->audit->record($user, 'recovery_codes_reissued', [
                'count' => self::CODE_COUNT,
                'execution' => 'local_shell',
            ]);
            if ($beforeCommit !== null) {
                $beforeCommit($codes);
            }

            return $codes;
        });
    }

    public function consume(User $user, #[\SensitiveParameter] string $plain): bool
    {
        return DB::transaction(function () use ($user, $plain): bool {
            DB::table('recovery_codes')
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->get();
            $codes = RecoveryCode::query()
                ->where('user_id', $user->getKey())
                ->get()
                ->filter(static fn (RecoveryCode $code): bool => $code->consumed_at === null);

            foreach ($codes as $code) {
                if (Hash::check(strtoupper(trim($plain)), $code->code_hash)) {
                    $code->forceFill(['consumed_at' => now()])->save();

                    return true;
                }
            }

            return false;
        });
    }
}
