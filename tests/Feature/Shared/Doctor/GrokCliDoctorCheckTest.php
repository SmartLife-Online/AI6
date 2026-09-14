<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Shared\Doctor\GrokCliDoctorCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Agents\BuildsGrokHome;
use Tests\Fixtures\Agents\FakeGrokBinary;
use Tests\TestCase;

final class GrokCliDoctorCheckTest extends TestCase
{
    use BuildsGrokHome;

    protected function tearDown(): void
    {
        $this->destroyGrokFixture();
        parent::tearDown();
    }

    public function test_unconfigured_provider_does_not_break_doctor(): void
    {
        config(['ai6.grok.binary' => '', 'ai6.grok.pinned_version' => '']);
        $result = (new GrokCliDoctorCheck)->run();
        self::assertTrue($result->passed);
        self::assertSame('nicht erbracht', $result->details['Reale CLI-Evidenz']);
    }

    public function test_version_and_discovery_probe_do_not_supply_runtime_evidence(): void
    {
        $this->createGrokFixture();
        config(['ai6.process.policies.control.working_roots' => [base_path(), $this->root]]);
        config(['ai6.execution_mailboxes.agent_root' => $this->root.'/inputs', 'ai6.execution_mailboxes.agent_output_root' => $this->root.'/outputs']);
        config(['ai6.grok.binary' => FakeGrokBinary::create($this->wrappers), 'ai6.grok.pinned_version' => '1.0.5']);
        $result = (new GrokCliDoctorCheck)->run();
        self::assertFalse($result->passed);
        self::assertStringContainsString('agent_grok_capability_unproven', implode(' ', $result->details));
        self::assertStringContainsString('kein Modellturn', $result->details['Reale CLI-Evidenz']);
        self::assertSame('Sandbox vorbereitet', $result->details['Sandboxvorbereitung']);
    }

    /** @return list<array{string, string, string}> */
    public static function sandboxFailures(): array
    {
        return [
            ['sandbox_unprepared', 'agent_grok_sandbox_unprepared', 'nicht vorbereitet'],
            ['sandbox_namespace', 'agent_grok_sandbox_role_unverifiable', 'in dieser Rolle nicht nachweisbar'],
            ['sandbox_bad_json', 'agent_grok_sandbox_unprepared', 'nicht vorbereitet'],
            ['sandbox_wrong_exit', 'agent_grok_sandbox_unprepared', 'nicht vorbereitet'],
            ['sandbox_missing_init', 'agent_grok_sandbox_unprepared', 'nicht vorbereitet'],
            ['sandbox_duplicate_init', 'agent_grok_sandbox_unprepared', 'nicht vorbereitet'],
        ];
    }

    #[DataProvider('sandboxFailures')]
    public function test_sandbox_preparation_failure_blocks_real_evidence(string $scenario, string $reason, string $detail): void
    {
        $this->createGrokFixture();
        config(['ai6.process.policies.control.working_roots' => [base_path(), $this->root]]);
        config(['ai6.execution_mailboxes.agent_root' => $this->root.'/inputs', 'ai6.execution_mailboxes.agent_output_root' => $this->root.'/outputs']);
        config(['ai6.grok.binary' => FakeGrokBinary::create($this->wrappers, $scenario), 'ai6.grok.pinned_version' => '1.0.5']);
        $result = (new GrokCliDoctorCheck)->run();
        self::assertFalse($result->passed);
        self::assertSame('nicht erbracht', $result->details['Reale CLI-Evidenz']);
        self::assertStringContainsString($reason, implode(' ', $result->details));
        self::assertStringContainsString($detail, implode(' ', $result->details));
        self::assertStringNotContainsString('Sandbox vorbereitet', implode(' ', $result->details));
    }

    public function test_unwritable_staging_root_is_role_unverifiable(): void
    {
        $this->createGrokFixture();
        config(['ai6.process.policies.control.working_roots' => [base_path(), $this->root]]);
        config(['ai6.execution_mailboxes.agent_root' => $this->root.'/inputs', 'ai6.execution_mailboxes.agent_output_root' => $this->root.'/outputs',
            'ai6.grok.binary' => FakeGrokBinary::create($this->wrappers), 'ai6.grok.pinned_version' => '1.0.5']);
        chmod($this->root.'/inputs', 0500);
        try {
            $result = (new GrokCliDoctorCheck)->run();
            self::assertFalse($result->passed);
            self::assertStringContainsString('agent_grok_sandbox_role_unverifiable', implode(' ', $result->details));
            self::assertSame('nicht erbracht', $result->details['Reale CLI-Evidenz']);
        } finally {
            chmod($this->root.'/inputs', 0700);
        }
    }
}
