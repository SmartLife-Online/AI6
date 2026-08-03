<?php

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Doctor\DoctorCommand;
use App\AI6\Shared\Runtime\RuntimeHealthCommand;
use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use App\AI6\Shared\Runtime\RuntimeSelfTestCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

$workerHeartbeatService = 'ai6.runtime.heartbeat.worker';

if (ini_set('zend.exception_ignore_args', '1') === false) {
    throw new RuntimeException('AI6 requires exception arguments to be hidden.');
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: static function (): void {
            Route::get('/health', static fn () => response()->json(['status' => 'ok']));
        },
    )
    ->withCommands([
        DoctorCommand::class,
        RuntimeHealthCommand::class,
        RuntimeSelfTestCommand::class,
    ])
    ->withSingletons([
        $workerHeartbeatService => static fn (): RuntimeHeartbeat => new RuntimeHeartbeat(RuntimeHeartbeat::WORKER_DIRECTORY),
    ])
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(static function (ConfigurationException $exception) {
            return response('Interner Konfigurationsfehler.', 500)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        });
    })
    ->booted(static function () use ($workerHeartbeatService): void {
        Event::listen(Looping::class, static function () use ($workerHeartbeatService): void {
            $directory = getenv('AI6_HEARTBEAT_DIRECTORY');

            if ($directory !== RuntimeHeartbeat::WORKER_DIRECTORY) {
                return;
            }

            /** @var RuntimeHeartbeat $heartbeat */
            $heartbeat = app($workerHeartbeatService);
            $heartbeat->write('worker');
        });
    })
    ->create();
