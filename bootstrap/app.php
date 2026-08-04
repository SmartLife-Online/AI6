<?php

use App\AI6\Auth\CannotRemoveLastAdministrator;
use App\AI6\Auth\Console\CreateAdministratorCommand;
use App\AI6\Auth\Console\ReissueRecoveryCodesCommand;
use App\AI6\Auth\Http\EnsureActiveUser;
use App\AI6\Auth\Http\EnsureCompletedAuthentication;
use App\AI6\Auth\StepUpRequiredException;
use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Doctor\DoctorCommand;
use App\AI6\Shared\Http\BlockPersistentLoginCookies;
use App\AI6\Shared\Http\ContentSecurityPolicy;
use App\AI6\Shared\Http\EnforceHttpsOrPrivateAccess;
use App\AI6\Shared\Http\ResolveTrustedProxies;
use App\AI6\Shared\Runtime\RuntimeHealthCommand;
use App\AI6\Shared\Runtime\RuntimeHeartbeat;
use App\AI6\Shared\Runtime\RuntimeSelfTestCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

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
        CreateAdministratorCommand::class,
        ReissueRecoveryCodesCommand::class,
        DoctorCommand::class,
        RuntimeHealthCommand::class,
        RuntimeSelfTestCommand::class,
    ])
    ->withSingletons([
        $workerHeartbeatService => static fn (): RuntimeHeartbeat => new RuntimeHeartbeat(RuntimeHeartbeat::WORKER_DIRECTORY),
    ])
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(TrustProxies::class, ResolveTrustedProxies::class);
        $middleware->append([
            ContentSecurityPolicy::class,
            EnforceHttpsOrPrivateAccess::class,
            BlockPersistentLoginCookies::class,
        ]);
        $middleware->preventRequestForgery();
        $middleware->web(append: [EnsureActiveUser::class, EnsureCompletedAuthentication::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(static function (
            CannotRemoveLastAdministrator $exception,
            Request $request,
        ) {
            return response()->json(['message' => 'Die Aktion kann nicht ausgeführt werden.'], 409);
        });
        $exceptions->render(static function (ConfigurationException $exception) {
            return response('Interner Konfigurationsfehler.', 500)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        });
        $exceptions->render(static function (StepUpRequiredException $_exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Eine frische Step-up-Bestätigung ist erforderlich.'], 403);
            }

            return response('Eine frische Step-up-Bestätigung ist erforderlich.', 403)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        });
        $exceptions->respond(static function (Response $response, Throwable $_exception, Request $request): Response {
            try {
                return app(ContentSecurityPolicy::class)->apply($response, $request);
            } catch (Throwable) {
                return ContentSecurityPolicy::applyFailurePolicy($response);
            }
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
