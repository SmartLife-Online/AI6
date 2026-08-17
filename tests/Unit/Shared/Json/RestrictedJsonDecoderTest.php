<?php

namespace Tests\Unit\Shared\Json;

use App\AI6\Shared\Json\JsonDecodingError;
use App\AI6\Shared\Json\JsonDecodingException;
use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Process\ProcessPolicyRegistry;
use App\AI6\Shared\Redaction\RedactionContext;
use App\AI6\Shared\Redaction\Redactor;
use Tests\TestCase;

final class RestrictedJsonDecoderTest extends TestCase
{
    public function test_it_decodes_a_restricted_json_object(): void
    {
        self::assertSame(['answer' => ['values' => [1, true, null]]], $this->decoder()->decode(
            '{"answer":{"values":[1,true,null]}}',
            $this->context(),
        ));
    }

    public function test_it_rejects_duplicate_keys_invalid_utf8_and_malformed_json_without_values(): void
    {
        $cases = [
            ['{"answer":1,"answer":2}', JsonDecodingError::DUPLICATE_KEY],
            ["{\"answer\":\"\xC3\x28\"}", JsonDecodingError::INVALID_UTF8],
            ['{"answer":', JsonDecodingError::INVALID_JSON],
        ];

        foreach ($cases as [$input, $reason]) {
            try {
                $this->decoder()->decode($input, $this->context());
                self::fail('The invalid JSON input was accepted.');
            } catch (JsonDecodingException $exception) {
                self::assertSame($reason, $exception->reason);
                self::assertStringNotContainsString($input, $exception->getMessage());
            }
        }
    }

    public function test_it_enforces_the_existing_agent_output_limit_and_structural_limits(): void
    {
        config(['ai6.process.policies.agent.output_limit_bytes' => '8']);
        $this->assertReason('{"value":1}', JsonDecodingError::SIZE_EXCEEDED, $this->decoder());

        $this->assertReason('[[[[]]]]', JsonDecodingError::NESTING_EXCEEDED, $this->decoder(2, 10));
        $this->assertReason('[1,2,3]', JsonDecodingError::ELEMENT_LIMIT_EXCEEDED, $this->decoder(16, 2));
    }

    private function assertReason(string $input, JsonDecodingError $reason, RestrictedJsonDecoder $decoder): void
    {
        try {
            $decoder->decode($input, $this->context());
            self::fail('The restricted JSON decoder accepted an over-limit document.');
        } catch (JsonDecodingException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    private function decoder(int $depth = 16, int $elements = 1000): RestrictedJsonDecoder
    {
        return new RestrictedJsonDecoder(
            $this->app->make(Redactor::class),
            ProcessPolicyRegistry::fromConfiguredValues(),
            $depth,
            $elements,
        );
    }

    private function context(): RedactionContext
    {
        return new RedactionContext('project-test', 'run-test', 'restricted-json');
    }
}
