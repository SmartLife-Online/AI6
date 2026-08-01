<?php

namespace App\AI6\Shared\Runtime;

use Illuminate\Console\Command;

final class RuntimeHealthCommand extends Command
{
    protected $signature = 'ai6:runtime-health {--role= : Zu prüfende Laufzeitrolle}';

    protected $description = 'Prüft Rollen-Heartbeat und Container-Boot-ID ohne Datenbankzugriff.';

    public function handle(): int
    {
        $role = (string) $this->option('role');
        $directory = getenv('AI6_HEARTBEAT_DIRECTORY');
        $maxAge = getenv('AI6_HEARTBEAT_MAX_AGE');

        if (! in_array($role, ['worker', 'scheduler'], true)
            || ! is_string($directory)
            || $directory === ''
            || ! is_string($maxAge)
            || preg_match('/\A[1-9][0-9]*\z/D', $maxAge) !== 1
        ) {
            $this->components->error(sprintf('Rolle %s ist nicht gesund (Alter unbekannt, Frist unbekannt).', $role === '' ? 'unbekannt' : $role));

            return self::FAILURE;
        }

        try {
            $status = (new RuntimeHeartbeat($directory))->status($role, (int) $maxAge);
        } catch (\RuntimeException) {
            $status = ['healthy' => false, 'age' => null];
        }

        $age = $status['age'] === null ? 'unbekannt' : $status['age'].'s';
        $message = sprintf('Rolle %s ist %s (Alter %s, Frist %ss).', $role, $status['healthy'] ? 'gesund' : 'nicht gesund', $age, $maxAge);

        if ($status['healthy']) {
            $this->components->info($message);

            return self::SUCCESS;
        }

        $this->components->error($message);

        return self::FAILURE;
    }
}
