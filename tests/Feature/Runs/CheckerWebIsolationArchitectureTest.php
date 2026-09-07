<?php

namespace Tests\Feature\Runs;

use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

final class CheckerWebIsolationArchitectureTest extends TestCase
{
    public function test_registered_web_and_livewire_entry_points_cannot_reach_the_checker_execution_path(): void
    {
        $forbidden = [
            'CheckRunner',
            'CheckerExecutionProcessor',
            'ControlProcessRunner',
            'ExecutionMailbox',
            'RunCheckStep',
            'AgentExecutionRunner',
            'AgentExecutionProcessor',
            'dispatchOrCollect',
            'processNext',
        ];
        $checked = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            $class = str_contains($action, '@') ? strstr($action, '@', true) : $action;
            if (! is_string($class) || ! str_starts_with($class, 'App\\') || isset($checked[$class])) {
                continue;
            }
            $checked[$class] = true;
            $reflection = new ReflectionClass($class);
            $path = $reflection->getFileName();
            self::assertIsString($path, $class);
            $source = file_get_contents($path);
            self::assertIsString($source, $class);
            foreach ($forbidden as $symbol) {
                self::assertStringNotContainsString($symbol, $source, $class.' must not reach the checker execution path.');
            }
        }

        self::assertNotEmpty($checked);
        // The checker seam: exactly RunCheckStep reaches CheckRunner::dispatchOrCollect().
        self::assertSame(
            ['app/AI6/Runs/RunCheckStep.php'],
            $this->applicationFilesContaining('->checks->dispatchOrCollect('),
        );
        // The agent-turn seam: exactly the four turn consumers reach
        // AgentExecutionRunner::dispatchOrCollect(), as one closed list —
        // no other class, and in particular no routed controller, may.
        self::assertSame(
            [
                'app/AI6/Reviews/FindingVerificationRound.php',
                'app/AI6/Reviews/ReviewRound.php',
                'app/AI6/Reviews/SecurityReviewStep.php',
                'app/AI6/Runs/RunImplementation.php',
            ],
            $this->applicationFilesContaining('->turns->dispatchOrCollect('),
        );
        self::assertSame(
            ['app/AI6/Shared/Process/ExecutionMailboxCommand.php'],
            $this->applicationFilesContaining('->processNext('),
        );
        // The two checks above are bound to the property names ->checks-> and
        // ->turns->, so a future, non-routed consumer calling
        // dispatchOrCollect() through a differently named property would
        // appear in neither closed list and slip past this guard entirely.
        // This third assertion binds the bare needle instead — the union of
        // both call-site lists above plus the two classes that declare the
        // method itself (their own `function dispatchOrCollect(` line also
        // contains the needle) — so any new caller, regardless of the
        // property name it is reached through, grows this list and turns
        // the inventory red.
        self::assertSame(
            [
                'app/AI6/Agents/AgentExecutionRunner.php',
                'app/AI6/Checks/CheckRunner.php',
                'app/AI6/Reviews/FindingVerificationRound.php',
                'app/AI6/Reviews/ReviewRound.php',
                'app/AI6/Reviews/SecurityReviewStep.php',
                'app/AI6/Runs/RunCheckStep.php',
                'app/AI6/Runs/RunImplementation.php',
            ],
            $this->applicationFilesContaining('dispatchOrCollect('),
        );
    }

    /** @return list<string> */
    private function applicationFilesContaining(string $needle): array
    {
        $root = dirname(__DIR__, 3);
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/app/AI6', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (is_string($source) && str_contains($source, $needle)) {
                $matches[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($matches);

        return $matches;
    }
}
