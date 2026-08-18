<?php

namespace Tests\Unit\HumanLoop;

use PHPUnit\Framework\TestCase;

final class HumanRequestArchitectureTest extends TestCase
{
    public function test_human_request_pages_stay_read_surfaces(): void
    {
        $root = dirname(__DIR__, 3);
        foreach ([
            $root.'/app/AI6/HumanLoop/AttentionInboxPage.php',
            $root.'/app/AI6/HumanLoop/HumanRequestDetailPage.php',
        ] as $path) {
            $page = file_get_contents($path);
            self::assertIsString($page);
            foreach (['RunOrchestrator', '->update(', '->create(', '->delete(', 'Queue::', 'dispatch('] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $page, $path.': '.$forbidden);
            }
        }

        $controller = file_get_contents($root.'/app/AI6/HumanLoop/Http/HumanRequestAnswerController.php');
        self::assertIsString($controller);
        self::assertStringContainsString('HumanRequestService', $controller);
        self::assertStringNotContainsString('RunOrchestrator', $controller);
        self::assertStringNotContainsString('HardenedGitRunner', $controller);
        self::assertStringNotContainsString('ControlProcessRunner', $controller);
    }
}
