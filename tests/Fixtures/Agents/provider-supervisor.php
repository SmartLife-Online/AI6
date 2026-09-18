<?php

use App\AI6\Agents\AgentAdapter;
use App\AI6\Agents\AgentExecutionProcessor;
use App\AI6\Agents\CodexCliAdapter;
use App\AI6\Agents\GitHubCopilotCliAdapter;
use App\AI6\Agents\GrokCliAdapter;
use App\AI6\Agents\ProviderCapabilityPublisher;
use App\AI6\Agents\ProviderCapabilityReport;
use App\AI6\Agents\ProviderCredentialStore;
use App\AI6\Agents\ProviderOnboarding;
use App\AI6\Shared\Doctor\CodexCliDoctorCheck;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\Fixtures\Agents\MissingAnswerAdapter;

// Test-only role driver: a fresh application inside real read-only input mounts.
require dirname(__DIR__, 3).'/vendor/autoload.php';

if (! isset($argv[1]) || ! is_file($argv[1])) {
    throw new RuntimeException('The supervisor test configuration is missing.');
}
$settings = json_decode((string) file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, static function () use ($settings): void {
    config($settings);
});
$app->make(Kernel::class)->bootstrap();
if (config('ai6.fixture.missing_answer') === true) {
    $app->bind(AgentAdapter::class, static fn () => new MissingAnswerAdapter);
}
$boot = trim(AgentExecutionProcessor::readBytes(ProviderOnboarding::path('presence_root').'/boot-id', 64));
$expired = false;
$pulse = static function () use ($boot, &$expired): void {
    app(ProviderCapabilityPublisher::class)->pulse($boot);
    if (! $expired && config('ai6.fixture.expire_provider_report') === true && AgentExecutionProcessor::executing()) {
        $reports = app(ProviderCapabilityReport::class);
        $document = $reports->read('codex_cli');
        if ($document === null) {
            throw new RuntimeException('The initial fixture report is missing.');
        }
        $store = app(ProviderCredentialStore::class);
        $store->locked(fn () => $store->publish('codex_cli', $document['generation'], $document['rows'], time() - 3600, $boot));
        $expired = true;
    }
};
$pulse();
$capabilityProbes = 0;
$app->resolving(CodexCliDoctorCheck::class, static function () use (&$capabilityProbes): void {
    $capabilityProbes++;
});
$processed = app(AgentExecutionProcessor::class)->processNext($boot, $pulse);
$commands = [];
foreach ([CodexCliAdapter::class, GrokCliAdapter::class, GitHubCopilotCliAdapter::class] as $adapter) {
    $commands[$adapter] = app($adapter)->lastCommand;
}
echo json_encode(['processed' => $processed, 'commands' => $commands, 'capability_probes' => $capabilityProbes], JSON_THROW_ON_ERROR);
