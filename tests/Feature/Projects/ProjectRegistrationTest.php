<?php

namespace Tests\Feature\Projects;

use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectMembership;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ProjectRegistrationTest extends ProjectRegistrationTestCase
{
    public function test_readme_documents_registration_metadata_membership_and_states(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 3).'/README.md');
        self::assertIsString($readme);

        foreach ([
            'Projektregistrierung und vertrauenswürdige Metadaten',
            'Remote, Control-Branch, Hostkey-Bindung und relative Projektkennung',
            'bin2hex(random_bytes(16))',
            'genau 32 Zeichen aus dem festen Alphabet `[0-9a-f]`',
            'Mitgliedschaft mit der Projektrolle `admin`',
            '`not_provisioned`',
            '`provisioning`',
            '`provisioned`',
            '`provisioning_failed`',
            'ausschließlich jetzt wird der öffentliche Deploy-Key angezeigt',
        ] as $requiredText) {
            self::assertStringContainsString($requiredText, $readme);
        }
    }

    public function test_global_administrator_registers_project_with_atomic_admin_membership(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);

        $response = $this->actingAs($administrator)->post(route('projects.store'), $this->registrationPayload([
            'host_key_fingerprint' => 'sha256:'.$this->fingerprintPayload.'=',
            'project_identifier' => '../client-choice',
        ]));

        $project = Project::query()->sole();
        $response->assertRedirect(route('projects.show', $project));
        self::assertSame('git@git.example.test:acme/project.git', $project->remote);
        self::assertSame('refs/heads/main', $project->control_branch);
        self::assertSame($this->fingerprint, $project->host_key_fingerprint);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', (string) $project->project_identifier);
        self::assertNotSame('../client-choice', $project->project_identifier);
        self::assertSame(ProjectProvisioningStatus::NOT_PROVISIONED, $project->provisioning_status);

        $membership = ProjectMembership::query()->sole();
        self::assertSame($administrator->getKey(), $membership->user_id);
        self::assertSame($project->getKey(), $membership->project_id);
        self::assertSame(ProjectRole::ADMIN, $membership->role);

        $this->actingAs($administrator)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee($project->remote)
            ->assertSee($project->control_branch)
            ->assertSee($project->project_identifier)
            ->assertSee(ProjectProvisioningStatus::NOT_PROVISIONED->value);
        $this->actingAs($administrator)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee($project->remote)
            ->assertSee($project->control_branch)
            ->assertSee($project->project_identifier)
            ->assertSee(ProjectProvisioningStatus::NOT_PROVISIONED->value);
    }

    public function test_only_global_administrator_can_reach_registration_routes(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $this->actingAs($administrator)->get(route('projects.create'))->assertOk();

        foreach (ProjectRole::cases() as $role) {
            $actor = $this->createUser();
            $existingProject = $this->createProject('Rollenprojekt '.$role->value);
            $this->addMembership($actor, $existingProject, $role);
            $before = Project::query()->count();

            $this->actingAs($actor)->get(route('projects.create'))->assertForbidden();
            $this->actingAs($actor)
                ->post(route('projects.store'), $this->registrationPayload(['name' => 'Verboten '.$role->value]))
                ->assertForbidden();
            self::assertSame($before, Project::query()->count());
        }

        $ordinaryUser = $this->createUser();
        $before = Project::query()->count();
        $this->actingAs($ordinaryUser)
            ->post(route('projects.store'), $this->registrationPayload(['name' => 'Verboten ohne Rolle']))
            ->assertForbidden();
        self::assertSame($before, Project::query()->count());
    }

    public function test_remote_protocol_host_path_and_ref_rejections_are_named_without_network(): void
    {
        Http::fake();
        $administrator = $this->createUser(['is_global_admin' => true]);
        $cases = [
            ['remote' => 'https://git.example.test/acme/project.git', 'field' => 'remote', 'message' => 'Das Remote-Protokoll ist nicht erlaubt.'],
            ['remote' => 'git@unknown.example:acme/project.git', 'field' => 'remote', 'message' => 'Der Remote-Host ist nicht erlaubt.'],
            ['remote' => 'git@git.example.test:other/project.git', 'field' => 'remote', 'message' => 'Der Remote-Pfad ist nicht erlaubt.'],
            ['control_branch' => 'refs/tags/release', 'field' => 'control_branch', 'message' => 'Der Control-Branch ist nicht erlaubt.'],
        ];

        foreach ($cases as $index => $case) {
            $payload = $this->registrationPayload(['name' => 'Abgelehnt '.$index]);
            $payload[$case['field'] === 'control_branch' ? 'control_branch' : 'remote'] = $case[$case['field'] === 'control_branch' ? 'control_branch' : 'remote'];

            $this->actingAs($administrator)
                ->post(route('projects.store'), $payload)
                ->assertSessionHasErrors([$case['field'] => $case['message']]);
            $this->actingAs($administrator)
                ->get(route('projects.create'))
                ->assertOk()
                ->assertSee($this->fingerprint);
        }

        self::assertSame(0, Project::query()->count());
        Http::assertNothingSent();
    }

    public function test_membership_failure_rolls_back_the_project(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_registration_membership
            BEFORE INSERT ON project_memberships
            BEGIN
                SELECT RAISE(ABORT, 'membership fixture failure');
            END
            SQL);

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($administrator)->post(route('projects.store'), $this->registrationPayload());
            self::fail('The membership fixture unexpectedly allowed registration.');
        } catch (QueryException) {
            self::assertSame(0, Project::query()->count());
            self::assertSame(0, ProjectMembership::query()->count());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS reject_registration_membership');
        }
    }

    public function test_public_deploy_key_is_visible_only_when_provisioned(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $this->actingAs($administrator)->post(route('projects.store'), $this->registrationPayload());
        $project = Project::query()->sole();
        $publicKey = 'ssh-ed25519 AAAA-test-public-key';

        foreach (ProjectProvisioningStatus::cases() as $status) {
            $project->update([
                'provisioning_status' => $status,
                'public_deploy_key' => $publicKey,
            ]);

            $response = $this->actingAs($administrator)->get(route('projects.show', $project));
            $response->assertOk();
            if ($status === ProjectProvisioningStatus::PROVISIONED) {
                $response->assertSee($publicKey);
            } else {
                $response->assertDontSee($publicKey);
            }
        }
    }

    public function test_repository_files_and_client_path_suggestions_cannot_change_metadata(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ai6-registration-'.bin2hex(random_bytes(8));
        mkdir($root.DIRECTORY_SEPARATOR.'.git', 0700, true);
        file_put_contents($root.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'config', "remote=git@attacker.invalid:evil/repo.git\n");
        file_put_contents($root.DIRECTORY_SEPARATOR.'.gitattributes', "* filter=attacker\n");
        file_put_contents($root.DIRECTORY_SEPARATOR.'instructions.txt', "control_branch=refs/heads/attacker\n");

        try {
            $payload = $this->registrationPayload([
                'repository_path' => $root,
                'managed_path' => '../../attacker',
                'project_identifier' => 'ATTACKER',
            ]);
            $this->actingAs($administrator)->post(route('projects.store'), $payload)->assertRedirect();

            $project = Project::query()->sole();
            self::assertSame($payload['remote'], $project->remote);
            self::assertSame($payload['control_branch'], $project->control_branch);
            self::assertSame($this->fingerprint, $project->host_key_fingerprint);
            self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', (string) $project->project_identifier);
            self::assertNotSame('ATTACKER', $project->project_identifier);
        } finally {
            unlink($root.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'config');
            unlink($root.DIRECTORY_SEPARATOR.'.gitattributes');
            unlink($root.DIRECTORY_SEPARATOR.'instructions.txt');
            rmdir($root.DIRECTORY_SEPARATOR.'.git');
            rmdir($root);
        }
    }

    public function test_fingerprint_negative_cases_create_no_project_and_log_only_failure_class(): void
    {
        $administrator = $this->createUser(['is_global_admin' => true]);
        $pinnedPrefix = substr($this->fingerprintPayload, 0, 16);

        do {
            $unrelatedBytes = random_bytes(33);
            $unrelatedDigest = substr($unrelatedBytes, 0, 32);
            $unrelatedPayload = rtrim(base64_encode($unrelatedDigest), '=');
            $changedDigest = $unrelatedDigest;
            $changedDigest[0] = chr(ord($changedDigest[0]) ^ 1);
            $invalid = [
                'case changed payload' => 'SHA256:'.$this->changePayloadCase($unrelatedPayload),
                'bit changed' => 'SHA256:'.rtrim(base64_encode($changedDigest), '='),
                'truncated' => 'SHA256:'.substr($unrelatedPayload, 0, 42),
                '31 bytes' => 'SHA256:'.rtrim(base64_encode(substr($unrelatedBytes, 0, 31)), '='),
                '33 bytes' => 'SHA256:'.rtrim(base64_encode($unrelatedBytes), '='),
                'excess padding' => 'SHA256:'.$unrelatedPayload.'==',
                'non canonical padding' => 'SHA256:'.substr($unrelatedPayload, 0, 42).'B=',
                'invalid alphabet' => 'SHA256:'.substr($unrelatedPayload, 0, 42).'-=',
                'wrong prefix' => 'SHA512:'.$unrelatedPayload,
            ];
            $sharesPinnedPrefix = array_filter(
                $invalid,
                static fn (string $fingerprint): bool => str_contains($fingerprint, $pinnedPrefix),
            ) !== [];
        } while ($sharesPinnedPrefix);

        $failureClasses = [];

        foreach ($invalid as $label => $fingerprint) {
            $logger = new class
            {
                /** @var array<int, array{message: string, context: array<string, mixed>}> */
                public array $records = [];

                /** @param array<string, mixed> $context */
                public function warning(string $message, array $context = []): void
                {
                    $this->records[] = ['message' => $message, 'context' => $context];
                }
            };
            $this->app->instance('log', $logger);
            Log::clearResolvedInstance('log');
            $this->actingAs($administrator)
                ->post(route('projects.store'), $this->registrationPayload([
                    'name' => 'Fingerprint '.$label,
                    'host_key_fingerprint' => $fingerprint,
                ]))
                ->assertRedirect();

            $response = $this->actingAs($administrator)->get(route('projects.create'));
            $response->assertOk();
            $response->assertSeeText('Der Hostkey-Fingerprint wurde abgelehnt.');
            $response->assertSee($fingerprint);
            $response->assertDontSee($this->fingerprint);
            $response->assertDontSee($pinnedPrefix);
            self::assertCount(1, $logger->records);
            self::assertSame('Project registration metadata rejected.', $logger->records[0]['message']);
            self::assertSame(['failure_class'], array_keys($logger->records[0]['context']));
            self::assertContains($logger->records[0]['context']['failure_class'], ['format_error', 'digest_mismatch']);
            $failureClasses[] = $logger->records[0]['context']['failure_class'];
            $serialized = json_encode($logger->records[0], JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString($fingerprint, $serialized);
            self::assertStringNotContainsString($this->fingerprint, $serialized);
            self::assertSame(0, Project::query()->count());
        }

        self::assertEqualsCanonicalizing(['format_error', 'digest_mismatch'], array_values(array_unique($failureClasses)));
    }
}
