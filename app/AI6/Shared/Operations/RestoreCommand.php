<?php

namespace App\AI6\Shared\Operations;

use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class RestoreCommand extends Command
{
    protected $signature = 'ai6:restore {source}';

    protected $description = 'Prüft und restauriert ein Backup im gestoppten Wartungsfenster.';

    public function handle(): int
    {
        try {
            $source = $this->argument('source');
            if (! is_string($source)) {
                throw new BackupException('restore_source_invalid');
            }
            $result = app(BackupSet::class)->restore($source);
            $this->info('Restore vollständig. Sessions und Login-Challenges widerrufen.');
            $this->line(sprintf('Retention: Artefakte=%d, Runlogs=%d, Checklogs=%d, aufgeschoben=%d, fehlgeschlagen=%d',
                $result->artifactsPurged, $result->runLogsPurged, $result->checkLogsPurged, $result->deferred, $result->failed));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $incomplete = $exception instanceof BackupException && $exception->paths !== [];
            $this->error(($incomplete ? 'Restore unvollständig: ' : 'Restore abgewiesen: ')
                .($exception instanceof BackupException ? $exception->reason : 'restore_failed'));
            if ($incomplete) {
                foreach ($exception->paths as $path => $entry) {
                    $line = $entry['label'].': '.$entry['state'].'; Sicherungssuffix: '.substr($entry['previous'], strlen($path))
                        .'; Pfad: '.$path.'; vorherige Kopie: '.$entry['previous']
                        .' ('.(file_exists($entry['previous']) ? 'vorhanden' : 'nicht vorhanden').')';
                    $safe = app(Redactor::class)->redact($line, new RedactionContext('instance', null, 'restore-command'))->text;
                    $this->output->writeln($safe, OutputInterface::OUTPUT_RAW);
                }
                $this->line('Rollen gestoppt lassen. Rückweg über die vorherigen Kopien: README.');
            }

            return self::FAILURE;
        }
    }
}
