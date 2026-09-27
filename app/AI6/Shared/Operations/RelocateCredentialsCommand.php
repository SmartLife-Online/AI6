<?php

namespace App\AI6\Shared\Operations;

use Illuminate\Console\Command;
use Throwable;

final class RelocateCredentialsCommand extends Command
{
    protected $signature = 'ai6:relocate-credentials {--check : Prüft vor der Dateiübernahme, ohne Referenzen zu ändern.}';

    protected $description = 'Übernimmt Deploy-Key-Referenzen in das private Credential-Verzeichnis.';

    public function handle(): int
    {
        try {
            $count = app(BackupSet::class)->relocateCredentials((bool) $this->option('check'));
            $this->info(($this->option('check') ? 'Übernahme geprüft: ' : 'Übernahme abgeschlossen: ').$count.' Projektreferenzen.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Übernahme abgewiesen: '.($exception instanceof BackupException ? $exception->reason : 'credentials_failed'));

            return self::FAILURE;
        }
    }
}
