<?php

namespace Tests\Unit\Agents;

use App\AI6\Agents\DelegatingProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessIsolationBoundary;
use App\AI6\Shared\Process\ProcessPolicy;
use App\AI6\Shared\Process\ProcessPolicyName;
use App\AI6\Shared\Process\ProcessRequest;
use App\AI6\Shared\Process\ProcessStartRejectedException;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\TestCase;

final class DelegatingProcessIsolationBoundaryTest extends TestCase
{
    /**
     * The delegator's own refusal. It is deliberately distinct from every
     * message the isolation verifier raises, so a test can tell "the verifier
     * decided" from "the delegator never reached it" on any platform.
     */
    private const OWN_REFUSAL = 'The process policy is not isolated for the current runtime role.';

    /** The agent containment refusal. A checker request must never see it. */
    private const CONTAINMENT_REFUSAL = 'The turn containment boundary applies to the agent policy.';

    /**
     * This class is the container binding for the boundary interface. An
     * interface-typed constructor parameter would resolve back to it and
     * recurse until the process dies, so the resolution itself is the assertion.
     */
    public function test_the_container_resolves_the_bound_isolation_boundary(): void
    {
        self::assertInstanceOf(
            DelegatingProcessIsolationBoundary::class,
            $this->app->make(ProcessIsolationBoundary::class),
        );
    }

    public function test_agent_policy_outside_the_agent_role_uses_turn_containment(): void
    {
        config(['ai6.runtime_role' => 'worker']);
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-iso-'.bin2hex(random_bytes(4));
        $tree = $root.DIRECTORY_SEPARATOR.'tree';
        $io = $root.DIRECTORY_SEPARATOR.'io';
        self::assertTrue(mkdir($tree, 0700, true));
        self::assertTrue(mkdir($io, 0700, true));

        try {
            (new DelegatingProcessIsolationBoundary)->assertIsolated(
                $this->request($tree, $io, ProcessPolicyName::AGENT),
                $this->policy(ProcessPolicyName::AGENT, $tree),
            );
        } finally {
            @rmdir($io);
            @rmdir($tree);
            @rmdir($root);
        }
    }

    public function test_a_checker_policy_in_its_own_role_reaches_the_isolation_verifier(): void
    {
        config(['ai6.runtime_role' => 'checker']);
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-iso-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root, 0700, true));

        try {
            (new DelegatingProcessIsolationBoundary)->assertIsolated(
                $this->request($root, $root, ProcessPolicyName::CHECKER),
                $this->policy(ProcessPolicyName::CHECKER, $root),
            );
            self::fail('An unbound checker request must be refused.');
        } catch (ProcessStartRejectedException $exception) {
            // Which verifier check fires depends on platform and mounts. Only the
            // delegator's own refusal or the agent containment refusal would prove
            // the verifier was never reached.
            self::assertNotSame(self::OWN_REFUSAL, $exception->getMessage());
            self::assertNotSame(self::CONTAINMENT_REFUSAL, $exception->getMessage());
        } finally {
            @rmdir($root);
        }
    }

    public function test_a_checker_policy_outside_its_role_is_refused_without_agent_containment(): void
    {
        config(['ai6.runtime_role' => 'worker']);
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-iso-'.bin2hex(random_bytes(4));
        self::assertTrue(mkdir($root, 0700, true));

        try {
            (new DelegatingProcessIsolationBoundary)->assertIsolated(
                $this->request($root, $root, ProcessPolicyName::CHECKER),
                $this->policy(ProcessPolicyName::CHECKER, $root),
            );
            self::fail('A checker policy outside its role must be refused.');
        } catch (ProcessStartRejectedException $exception) {
            self::assertSame(self::OWN_REFUSAL, $exception->getMessage());
        } finally {
            @rmdir($root);
        }
    }

    private function policy(ProcessPolicyName $name, string $root): ProcessPolicy
    {
        return new ProcessPolicy($name, 5, 4096, [PHP_BINARY], [], [$root], false, 100);
    }

    private function request(string $cwd, string $io, ProcessPolicyName $policy): ProcessRequest
    {
        return new ProcessRequest(
            [PHP_BINARY, '-r', 'echo 1;'],
            $cwd,
            [],
            [],
            new RedactionContext('project-1', 'run-1', 'isolation'),
            policy: $policy,
            resultDirectory: $io,
            artifactDirectory: $io,
        );
    }
}
