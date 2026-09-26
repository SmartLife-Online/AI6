<?php

namespace Tests\Unit\Prompts;

use App\AI6\Prompts\PromptHelpGuestAccess;
use App\AI6\Shared\Config\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** TC-11: the one evaluation of AI6_PROMPT_HELP_GUEST_ACCESS. */
final class PromptHelpGuestAccessTest extends TestCase
{
    #[DataProvider('switchValues')]
    public function test_only_an_explicit_enabling_literal_opens_guest_access(mixed $value, bool $expected): void
    {
        config(['ai6.manual_prompt_help.guest_access' => $value]);

        self::assertSame($expected, $this->app->make(PromptHelpGuestAccess::class)->enabled());
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function switchValues(): iterable
    {
        yield 'missing' => [null, false];
        yield 'empty' => ['', false];
        yield 'boolean false' => [false, false];
        yield 'false' => ['false', false];
        yield 'zero' => ['0', false];
        yield 'no' => ['no', false];
        yield 'off in upper case' => ['OFF', false];
        yield 'boolean true' => [true, true];
        yield 'true' => ['true', true];
        yield 'one' => ['1', true];
        yield 'yes in mixed case' => ['Yes', true];
        yield 'on' => ['on', true];
    }

    #[DataProvider('invalidValues')]
    public function test_an_invalid_value_names_the_key_but_never_the_value(string $value): void
    {
        config(['ai6.manual_prompt_help.guest_access' => $value]);

        try {
            $this->app->make(PromptHelpGuestAccess::class)->enabled();
            self::fail('An invalid guest access value was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(PromptHelpGuestAccess::ENVIRONMENT_KEY, $exception->getMessage());
            self::assertStringNotContainsString($value, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'german yes' => ['ja'];
        yield 'number two' => ['2'];
        yield 'misspelled true' => ['truee'];
        yield 'free text' => ['guest-access-invalid-value'];
        yield 'parenthesized true' => ['(true)'];
        yield 'parenthesized false' => ['(false)'];
        yield 'null literal' => ['null'];
        yield 'parenthesized null' => ['(null)'];
        yield 'empty literal' => ['empty'];
        yield 'parenthesized empty' => ['(empty)'];
        yield 'quoted yes' => ['"yes"'];
    }
}
