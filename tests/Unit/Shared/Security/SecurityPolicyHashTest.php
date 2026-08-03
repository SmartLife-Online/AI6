<?php

namespace Tests\Unit\Shared\Security;

use App\AI6\Shared\Security\CanonicalByteFrame;
use App\AI6\Shared\Security\SecurityMeasure;
use App\AI6\Shared\Security\SecurityPolicy;
use App\AI6\Shared\Security\SecurityPolicyFactory;
use App\AI6\Shared\Security\SecurityPolicyHasher;
use App\AI6\Shared\Security\SecurityProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SecurityPolicyHashTest extends TestCase
{
    public function test_policy_hash_reproduces_the_committed_golden_vector(): void
    {
        $fixture = $this->fixture();
        $policy = new SecurityPolicy(
            SecurityProfile::from($fixture['profile']),
            $fixture['measures'],
            $fixture['reduced_mode_acknowledged'],
        );

        self::assertSame($fixture['expected_sha256'], $policy->hash());
    }

    public function test_hash_is_stable_across_input_order_and_valid_literal_spelling(): void
    {
        $configuration = [
            'profile' => 'STRICT',
            'acknowledge_reduced_mode' => 'OFF',
            'measures' => array_fill_keys(array_reverse(array_map(
                static fn (SecurityMeasure $measure): string => $measure->value,
                SecurityMeasure::cases(),
            )), 'YES'),
        ];

        $first = (new SecurityPolicyFactory)->inspect($configuration);
        $second = (new SecurityPolicyFactory)->inspect([
            'profile' => 'strict',
            'acknowledge_reduced_mode' => false,
            'measures' => array_fill_keys(array_map(
                static fn (SecurityMeasure $measure): string => $measure->value,
                SecurityMeasure::cases(),
            ), true),
        ]);

        self::assertInstanceOf(SecurityPolicy::class, $first);
        self::assertInstanceOf(SecurityPolicy::class, $second);
        self::assertSame($first->hash(), $second->hash());
    }

    public function test_profile_measure_and_acknowledgement_changes_each_change_the_hash(): void
    {
        $states = $this->fixture()['measures'];
        $baseline = new SecurityPolicy(SecurityProfile::STRICT, $states, false);
        self::assertNotSame($baseline->hash(), (new SecurityPolicy(SecurityProfile::CUSTOM, $states, false))->hash());
        self::assertNotSame($baseline->hash(), (new SecurityPolicy(SecurityProfile::STRICT, $states, true))->hash());

        foreach (SecurityMeasure::cases() as $measure) {
            $changed = $states;
            $changed[$measure->value] = false;
            self::assertNotSame($baseline->hash(), (new SecurityPolicy(SecurityProfile::CUSTOM, $changed, true))->hash());
        }
    }

    public function test_missing_measure_state_is_rejected_instead_of_being_hashed_as_disabled(): void
    {
        $states = $this->fixture()['measures'];
        $missing = SecurityMeasure::REQUIRE_AGENT_SANDBOX->value;
        unset($states[$missing]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing security measure state: '.$missing.'.');

        SecurityPolicyHasher::hash(SecurityProfile::STRICT, $states, false);
    }

    public function test_length_framing_and_nfc_prevent_boundary_ambiguity(): void
    {
        $left = CanonicalByteFrame::encode('AI6-SECURITY-POLICY-V1', ['ab', 'c']);
        $right = CanonicalByteFrame::encode('AI6-SECURITY-POLICY-V1', ['a', 'bc']);

        self::assertNotSame($left, $right);
        self::assertNotSame(hash('sha256', $left), hash('sha256', $right));
        self::assertSame(
            CanonicalByteFrame::encode('AI6-SECURITY-POLICY-V1', ['é']),
            CanonicalByteFrame::encode('AI6-SECURITY-POLICY-V1', ["e\u{0301}"]),
        );
        self::assertSame("AI6-SECURITY-POLICY-V1\0".pack('J', 2).'ab'.pack('J', 1).'c', $left);
    }

    /** @return array{profile: string, measures: array<string, bool>, reduced_mode_acknowledged: bool, expected_sha256: string} */
    private function fixture(): array
    {
        $contents = file_get_contents(__DIR__.'/Fixtures/policy-hash.json');
        self::assertNotFalse($contents);
        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);

        return $fixture;
    }
}
