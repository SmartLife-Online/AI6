<?php

namespace Tests\Unit\Projects;

use App\AI6\Projects\ProjectIdentifierGenerator;
use PHPUnit\Framework\TestCase;

final class ProjectIdentifierGeneratorTest extends TestCase
{
    public function test_identifiers_are_random_fixed_lowercase_hex_values(): void
    {
        $generator = new ProjectIdentifierGenerator;
        $identifiers = [];

        for ($iteration = 0; $iteration < 128; $iteration++) {
            $identifier = $generator->generate();
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $identifier);
            self::assertStringNotContainsString('/', $identifier);
            self::assertStringNotContainsString('\\', $identifier);
            self::assertStringNotContainsString('..', $identifier);
            self::assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $identifier));
            self::assertSame(strtolower($identifier), $identifier);
            $identifiers[] = $identifier;
        }

        self::assertCount(128, array_unique($identifiers));
    }
}
