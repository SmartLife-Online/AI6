<?php

namespace Tests\Unit\Shared\Config;

use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictBooleanParser;
use App\AI6\Shared\Config\StrictEnumParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StrictConfigurationParserTest extends TestCase
{
    #[DataProvider('validBooleanProvider')]
    public function test_boolean_parser_accepts_only_the_documented_literals(mixed $value, bool $expected): void
    {
        self::assertSame($expected, (new StrictBooleanParser)->parse('AI6_SECURITY_TEST', $value));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function validBooleanProvider(): iterable
    {
        yield 'native true' => [true, true];
        yield 'native false' => [false, false];
        yield 'integer one' => [1, true];
        yield 'integer zero' => [0, false];

        foreach ([
            'true' => true,
            'TRUE' => true,
            '1' => true,
            'yes' => true,
            'ON' => true,
            'false' => false,
            'FALSE' => false,
            '0' => false,
            'no' => false,
            'OFF' => false,
        ] as $literal => $expected) {
            yield $literal => [$literal, $expected];
        }
    }

    #[DataProvider('invalidBooleanProvider')]
    public function test_boolean_parser_rejects_invalid_missing_and_empty_values_without_echoing_them(mixed $value): void
    {
        $result = (new StrictBooleanParser)->parse('AI6_SECURITY_PRIVATE_TEST', $value);

        self::assertInstanceOf(ConfigurationViolation::class, $result);
        self::assertStringContainsString('AI6_SECURITY_PRIVATE_TEST', $result->message);
        self::assertStringContainsString('true, false, 1, 0, yes, no, on, off', $result->message);

        if (is_string($value) && $value !== '') {
            self::assertStringNotContainsString($value, $result->message);
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidBooleanProvider(): iterable
    {
        yield 'typo' => ['yess'];
        yield 'decimal string' => ['1.0'];
        yield 'empty string' => [''];
        yield 'missing value' => [null];
        yield 'float' => [1.0];
        yield 'whitespace' => [' true '];
    }

    public function test_enum_parser_is_case_insensitive_but_rejects_unknown_and_non_string_values(): void
    {
        $parser = new StrictEnumParser;
        self::assertSame('strict', $parser->parse('AI6_SECURITY_PROFILE', 'STRICT', ['strict', 'development', 'custom']));

        foreach (['private-profile', null, 1] as $invalid) {
            $result = $parser->parse('AI6_SECURITY_PROFILE', $invalid, ['strict', 'development', 'custom']);
            self::assertInstanceOf(ConfigurationViolation::class, $result);
            self::assertStringContainsString('AI6_SECURITY_PROFILE', $result->message);
            self::assertStringContainsString('strict, development, custom', $result->message);
            self::assertStringNotContainsString('private-profile', $result->message);
        }
    }
}
