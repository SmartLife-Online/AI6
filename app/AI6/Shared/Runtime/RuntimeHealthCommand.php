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
        try {
            if (! in_array($role, ['worker', 'scheduler'], true)) {
                throw new \RuntimeException('The health command role is invalid.');
            }
            $status = RuntimeHeartbeat::statusFromEnvironment($role);
        } catch (\RuntimeException) {
            $this->components->error(sprintf('Rolle %s ist nicht gesund (Alter unbekannt, Frist unbekannt).', $role === '' ? 'unbekannt' : $role));

            return self::FAILURE;
        }

        $age = $status['age'] === null ? 'unbekannt' : $status['age'].'s';
        $message = sprintf('Rolle %s ist %s (Alter %s, Frist %ss).', $role, $status['healthy'] ? 'gesund' : 'nicht gesund', $age, $status['max_age']);

        if ($status['healthy']) {
            $this->components->info($message);

            return self::SUCCESS;
        }

        $this->components->error($message);

        return self::FAILURE;
    }
}
