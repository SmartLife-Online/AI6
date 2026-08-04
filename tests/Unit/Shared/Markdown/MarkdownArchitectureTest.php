<?php

namespace Tests\Unit\Shared\Markdown;

use Tests\TestCase;

final class MarkdownArchitectureTest extends TestCase
{
    public function test_commonmark_capability_comes_from_the_existing_laravel_lock_tree(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('league/commonmark', $composer['require'] ?? []);

        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
        $packages = [];

        foreach ($lock['packages'] ?? [] as $package) {
            $packages[$package['name']] = $package;
        }

        self::assertSame('2.8.3', $packages['league/commonmark']['version'] ?? null);
        self::assertSame(
            '^2.8.1',
            $packages['laravel/framework']['require']['league/commonmark'] ?? null,
        );
    }

    public function test_markdown_policy_uses_the_central_redactor_without_a_second_rule_list(): void
    {
        $renderer = file_get_contents(app_path('AI6/Shared/Markdown/SafeMarkdownRenderer.php'));
        self::assertIsString($renderer);
        self::assertStringContainsString('private Redactor $redactor', $renderer);
        self::assertStringContainsString('$this->redactor->redact(', $renderer);
        self::assertStringContainsString('$redacted->text', $renderer);

        $policy = file_get_contents(app_path('AI6/Shared/Markdown/AllowedHtmlPolicy.php'));
        self::assertIsString($policy);
        self::assertStringNotContainsString('RedactionRule', $policy);
        self::assertStringNotContainsString('RedactionMatchType', $policy);
    }
}
