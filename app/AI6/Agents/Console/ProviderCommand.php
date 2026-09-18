<?php

namespace App\AI6\Agents\Console;

use App\AI6\Agents\ProviderCapabilityPublisher;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Agents\ProviderLogin;
use App\AI6\Agents\ProviderOnboarding;
use Illuminate\Console\Command;
use Throwable;

final class ProviderCommand extends Command
{
    protected $signature = 'ai6:provider {action : login oder logout} {alias : codex_cli, grok_cli oder github_copilot_cli}';

    protected $description = 'Providerzugang im laufenden Agentcontainer einrichten oder entziehen';

    public function handle(): int
    {
        $cancelled = false;
        if (defined('SIGINT') && defined('SIGTERM')) {
            $this->trap([SIGINT, SIGTERM], static function () use (&$cancelled): void {
                $cancelled = true;
            });
        }
        try {
            ProviderOnboarding::assertAgent();
            $alias = (string) $this->argument('alias');
            ProviderOnboarding::configuration($alias);
            $action = (string) $this->argument('action');
            if (! in_array($action, ['login', 'logout'], true)) {
                $this->components->error('Erlaubt sind ausschließlich login und logout.');

                return self::INVALID;
            }
            if ($action === 'logout') {
                app(ProviderCredentialStore::class)->replace($alias, null);
            } else {
                $secret = match ($alias) {
                    'github_copilot_cli' => $this->secret('GitHub-Token mit Copilot-Berechtigung (verdeckt)'),
                    'grok_cli' => $this->secret('xAI-API-Key mit Modellberechtigung (verdeckt)'),
                    default => null,
                };
                $shown = [];
                if ($alias === 'codex_cli') {
                    $this->line('Anmeldung im Browser: https://auth.openai.com/codex/device');
                }
                app(ProviderLogin::class)->login($alias, is_string($secret) ? $secret : null,
                    function (string $output) use (&$shown): void {
                        // Native output is never rendered. Only the short device
                        // code is extracted; the destination above is server-owned.
                        if (preg_match('/\b([A-Z0-9]{4}-[A-Z0-9]{4,5})\b/', $output, $match) === 1 && ! isset($shown[$match[1]])) {
                            $this->line('Gerätecode: '.$match[1]);
                            $shown[$match[1]] = true;
                        }
                    }, static function () use (&$cancelled): bool {
                        return $cancelled;
                    });
            }
            app(ProviderCapabilityPublisher::class)->recheck($alias);
            $this->components->info('Zugang aktualisiert. Den aktuellen Profilzustand zeigt ai6:doctor.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error('Provideraktion abgewiesen, fehlgeschlagen oder abgebrochen. Zugang und Freigabe mit ai6:doctor prüfen.');

            return self::FAILURE;
        }
    }
}
