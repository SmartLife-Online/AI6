<?php

namespace App\AI6\Auth\Console;

use App\AI6\Auth\EmailNormalizer;
use App\AI6\Auth\Models\User;
use App\AI6\Auth\RecoveryCodeManager;
use DomainException;
use Illuminate\Console\Command;

final class ReissueRecoveryCodesCommand extends Command
{
    protected $signature = 'ai6:reissue-recovery-codes
        {email : E-Mail-Adresse des Zielbenutzers}';

    protected $description = 'Ersetzt die Recovery-Codes eines aktiven Benutzers mit starkem Verfahren.';

    public function handle(EmailNormalizer $normalizer, RecoveryCodeManager $codes): int
    {
        $email = $this->argument('email');

        if (! is_string($email)) {
            $this->error('Der Zielbenutzer ist ungültig.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $normalizer->normalize($email))->first();

        if (! $user instanceof User || ! $user->is_active) {
            $this->error('Der Zielbenutzer ist nicht verfügbar.');

            return self::FAILURE;
        }

        try {
            $codes->reissue($user, function (array $plainCodes): void {
                $this->line(implode(PHP_EOL, $plainCodes));
            });
        } catch (DomainException) {
            $this->error('Für den Zielbenutzer ist kein starkes Verfahren registriert.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
