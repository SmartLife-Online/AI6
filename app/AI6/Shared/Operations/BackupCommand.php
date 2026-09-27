<?php

namespace App\AI6\Shared\Operations;

use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class BackupCommand extends Command
{
    protected $signature = 'ai6:backup {target?}';

    protected $description = 'Sichert eine ruhende Instanz ohne das getrennte Schlüsselpaket.';

    public function handle(): int
    {
        try {
            $target = $this->argument('target');
            $path = app(BackupSet::class)->backup(is_string($target) ? $target : null);
            $safe = app(Redactor::class)->redact($path, new RedactionContext('instance', null, 'backup-command'))->text;
            $this->output->writeln('Backup vollständig: '.$safe, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $incomplete = $exception instanceof BackupException && $exception->backupTarget !== null;
            $this->error(($incomplete ? 'Backup unvollständig: ' : 'Backup abgewiesen: ')
                .($exception instanceof BackupException ? $exception->reason : 'backup_failed'));
            if ($incomplete) {
                $safe = app(Redactor::class)->redact($exception->backupTarget, new RedactionContext('instance', null, 'backup-command'))->text;
                $this->output->writeln('Ziel: '.$safe, OutputInterface::OUTPUT_RAW);
            }

            return self::FAILURE;
        }
    }
}
