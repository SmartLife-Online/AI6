<?php

namespace Tests\Feature\Shared\Doctor;

use App\AI6\Shared\Doctor\GrokCliDoctorCheck;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

final class GrokCliNativeDoctorSmokeTest extends TestCase
{
    public function test_native_credential_free_doctor_reaches_the_real_sandbox_blocker(): void
    {
        $binary = getenv('AI6_GROK_BINARY');
        if (PHP_OS_FAMILY !== 'Linux' || ! is_string($binary) || $binary === '') {
            self::markTestSkipped('Nativer Doctor-Smoke benötigt Linux und AI6_GROK_BINARY; ohne Credentials und mit network_mode: none ausführen.');
        }
        self::assertFileExists($binary);
        self::assertNotSame(0, posix_geteuid());
        self::assertSame(['lo'], array_values(array_diff(scandir('/sys/class/net') ?: [], ['.', '..'])), 'Der Smoke benötigt einen netzfreien Container.');
        $root = sys_get_temp_dir().'/ai6-native-doctor-'.bin2hex(random_bytes(8));
        foreach (['inputs', 'outputs'] as $directory) {
            self::assertTrue(mkdir($root.'/'.$directory, 0700, true));
        }
        // Materialize the unchanged wrapper bytes on a POSIX filesystem even for a read-only Windows bind mount.
        self::assertTrue(copy(base_path('app/AI6/Shared/Process/control-process-wrapper.sh'), $root.'/wrapper.sh'));
        chmod($root.'/wrapper.sh', 0444);
        config(['ai6.execution_mailboxes.agent_root' => $root.'/inputs', 'ai6.execution_mailboxes.agent_output_root' => $root.'/outputs',
            'ai6.process.wrapper_script' => $root.'/wrapper.sh', 'ai6.process.policies.control.working_roots' => [$root],
            'ai6.grok.binary' => $binary, 'ai6.grok.pinned_version' => '1.0.5', 'ai6.grok.capability_evidence' => []]);
        try {
            // Doctor invokes the production probe() and probeSandbox(), with no auth projection.
            $result = (new GrokCliDoctorCheck)->run();
            self::assertFalse($result->passed);
            self::assertStringContainsString('agent_grok_sandbox_unprepared', implode(' ', $result->details));
            self::assertStringNotContainsString('agent_grok_surface_drift', implode(' ', $result->details));
            self::assertStringNotContainsString('Sandbox vorbereitet', implode(' ', $result->details));
            self::assertSame([], glob($root.'/inputs/execution-*'));
            self::assertSame([], glob($root.'/outputs/execution-*'));
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
