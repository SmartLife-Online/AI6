<?php

namespace Tests\Feature\Projects;

use App\AI6\Auth\Models\User;
use App\AI6\Auth\StepUpGuard;
use App\AI6\Git\Actions\QueueControlBranchChange;
use App\AI6\Git\ControlBranchChanger;
use App\AI6\Git\ControlOperationConfiguration;
use App\AI6\Git\ControlOperationPhase;
use App\AI6\Git\ControlOperationState;
use App\AI6\Git\ControlOperationType;
use App\AI6\Git\Models\ControlOperation;
use App\AI6\Git\ProjectOperationLease;
use App\AI6\Projects\Actions\ApproveProjectConfiguration;
use App\AI6\Projects\Actions\QueueProjectConfigRefresh;
use App\AI6\Projects\EffectiveProjectConfiguration;
use App\AI6\Projects\Models\Project;
use App\AI6\Projects\Models\ProjectConfigDraft;
use App\AI6\Projects\Models\ProjectConfigSnapshot;
use App\AI6\Projects\ProjectConfiguration;
use App\AI6\Projects\ProjectConfigurationConflict;
use App\AI6\Projects\ProjectConfigurationHasher;
use App\AI6\Projects\ProjectConfigurationParser;
use App\AI6\Projects\ProjectConfigurationSource;
use App\AI6\Projects\ProjectConfigurationStatus;
use App\AI6\Projects\ProjectProvisioningStatus;
use App\AI6\Projects\ProjectRole;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Auth\AuthFeatureTestCase;

final class ProjectConfigurationSnapshotTest extends AuthFeatureTestCase
{
    public function test_approval_is_cas_bound_idempotent_and_generation_stale_while_historic_binding_remains_resolvable(): void
    {
        $approver = $this->createUser(['is_global_admin' => true]);
        $project = $this->registeredProject();
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $configuration = new ProjectConfiguration(config('ai6.project_config.server_defaults'));
        $values = $configuration->values;
        $values['ticket_validation_profile'] = 'ai6_detail_v1';
        $candidate = new ProjectConfiguration($values);
        $hash = $this->app->make(ProjectConfigurationHasher::class)->hash($candidate);
        $draft = $this->validDraft($project, $approver->getKey(), $candidate, $hash);
        $resolver = $this->app->make(EffectiveProjectConfiguration::class);

        self::assertSame(ProjectConfigurationSource::SERVER_DEFAULTS, $resolver->for($project)->source);
        $approvalId = (string) Str::uuid();
        $action = $this->app->make(ApproveProjectConfiguration::class);
        try {
            $action->handle($approver, $project, $draft, $approvalId, $draft->control_commit,
                $draft->blob_sha, str_repeat('f', 64), $draft->control_generation);
            self::fail('A changed approval binding produced a config snapshot.');
        } catch (ProjectConfigurationConflict $exception) {
            self::assertSame('approval_binding_changed', $exception->conflict);
            self::assertSame(0, ProjectConfigSnapshot::query()->count());
        }
        $snapshot = $action->handle($approver, $project, $draft, $approvalId, $draft->control_commit,
            $draft->blob_sha, $draft->config_hash, $draft->control_generation);
        $effective = $resolver->for($project);
        self::assertSame(ProjectConfigurationSource::APPROVED_SNAPSHOT, $effective->source);
        self::assertSame('ai6_detail_v1', $effective->configuration->ticketValidationProfile()->value);
        self::assertSame($hash, $effective->configHash);
        self::assertSame($snapshot->getKey(), $action->handle($approver, $project, $draft, $approvalId,
            $draft->control_commit, $draft->blob_sha, $draft->config_hash, $draft->control_generation)->getKey());

        config(['ai6.control_operations.managed_ref_allowlist' => 'refs/heads/main,refs/heads/next']);
        $this->app->forgetInstance(ControlOperationConfiguration::class);
        $branchChange = $this->app->make(QueueControlBranchChange::class)->handle(
            $this->stepUpRequest($approver),
            $approver,
            $project->refresh(),
            'refs/heads/next',
            (string) Str::uuid(),
        );
        DB::table('jobs')->delete();
        $token = $this->app->make(ProjectOperationLease::class)->claim($branchChange, str_repeat('c', 32));
        self::assertIsInt($token);
        DB::table('control_operations')->where('id', $branchChange->id)->update([
            'phase' => 'remote_probed',
            'target_control_oid' => str_repeat('d', 64),
            'version' => DB::raw('version + 1'),
        ]);
        self::assertTrue($this->app->make(ControlBranchChanger::class)->advance($branchChange->refresh(), $token));
        $project->refresh();
        self::assertSame(1, $project->control_generation);
        self::assertSame(ProjectConfigurationSource::SERVER_DEFAULTS, $resolver->for($project)->source);
        self::assertSame($snapshot->getKey(), $resolver->snapshot((string) $snapshot->getKey())->snapshotId);

        try {
            $snapshot->forceFill(['config_hash' => str_repeat('f', 64)])->save();
            self::fail('An immutable config snapshot was updated.');
        } catch (QueryException) {
        }
    }

    public function test_changed_draft_does_not_replace_approval_and_missing_config_selects_visible_server_defaults(): void
    {
        $approver = $this->createUser();
        $project = $this->registeredProject();
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $defaults = new ProjectConfiguration(config('ai6.project_config.server_defaults'));
        $hasher = $this->app->make(ProjectConfigurationHasher::class);
        $approvedDraft = $this->validDraft($project, $approver->getKey(), $defaults, $hasher->hash($defaults));
        $snapshot = $this->app->make(ApproveProjectConfiguration::class)->handle(
            $approver, $project, $approvedDraft, (string) Str::uuid(), $approvedDraft->control_commit,
            $approvedDraft->blob_sha, $approvedDraft->config_hash, 0,
        );

        $changed = $defaults->values;
        $changed['limits']['max_fix_rounds'] = 4;
        $changedConfig = new ProjectConfiguration($changed);
        $this->validDraft($project, $approver->getKey(), $changedConfig, $hasher->hash($changedConfig));
        $resolver = $this->app->make(EffectiveProjectConfiguration::class);
        self::assertSame($snapshot->getKey(), $resolver->for($project)->snapshotId);
        self::assertSame([
            ['path' => 'limits.max_fix_rounds', 'before' => 3, 'after' => 4],
        ], $this->app->make(ProjectConfigurationStatus::class)->for($project)['changes']);

        $parser = $this->app->make(ProjectConfigurationParser::class);
        $canonical = $parser->parse($this->projectConfigurationYaml());
        $reordered = $parser->parse(str_replace(
            "version: 1\ntickets_path: tickets",
            "tickets_path: \"tickets\"\nversion: 1",
            $this->projectConfigurationYaml(),
        ));
        self::assertTrue($canonical->valid());
        self::assertTrue($reordered->valid());
        self::assertSame($hasher->hash($canonical->configuration), $hasher->hash($reordered->configuration));
        self::assertInstanceOf(ProjectConfiguration::class, $reordered->configuration);
        $this->validDraft($project, $approver->getKey(), $reordered->configuration, $hasher->hash($reordered->configuration));
        self::assertSame([], $this->app->make(ProjectConfigurationStatus::class)->for($project)['changes']);

        $operation = $this->operation($project, $approver->getKey());
        ProjectConfigDraft::query()->create([
            'project_id' => $project->getKey(), 'control_operation_id' => $operation->id,
            'control_commit' => $project->control_oid, 'blob_sha' => null, 'control_generation' => 0,
            'state' => 'absent', 'config_hash' => null, 'normalized_config' => null,
            'validation_errors' => [], 'redaction_matches' => [],
        ]);
        $effective = $resolver->for($project);
        self::assertSame(ProjectConfigurationSource::SERVER_DEFAULTS, $effective->source);
        self::assertNull($effective->blobSha);
        self::assertSame($hasher->hash($defaults), $effective->configHash);
    }

    public function test_http_approval_requires_and_consumes_action_bound_step_up_once(): void
    {
        $approver = $this->createUser();
        $secret = $this->createConfirmedTotp($approver);
        $project = $this->registeredProject();
        $this->addMembership($approver, $project, ProjectRole::APPROVER);
        $configuration = new ProjectConfiguration(config('ai6.project_config.server_defaults'));
        $hash = $this->app->make(ProjectConfigurationHasher::class)->hash($configuration);
        $draft = $this->validDraft($project, $approver->getKey(), $configuration, $hash);
        $payload = [
            'approval_id' => (string) Str::uuid(),
            'expected_control_commit' => $draft->control_commit,
            'expected_blob_sha' => $draft->blob_sha,
            'expected_config_hash' => $draft->config_hash,
            'expected_control_generation' => $draft->control_generation,
        ];
        $route = route('projects.configuration.approve', [$project, $draft]);
        $this->actingAs($approver);
        $this->preserveCurrentSessionCookie();
        $this->withCredentials();

        $this->post($route, $payload)->assertForbidden();
        self::assertSame(0, ProjectConfigSnapshot::query()->count());

        $this->post(route('auth.step-up.totp.verify', ['action' => 'project_config.approve']), [
            'code' => $this->currentTotpCode($secret),
        ])->assertSessionHas('status', 'Step-up bestätigt.');
        $this->post($route, $payload)->assertRedirect(route('projects.show', $project));
        self::assertSame(1, ProjectConfigSnapshot::query()->count());

        $this->post($route, $payload)->assertForbidden();
        self::assertSame(1, ProjectConfigSnapshot::query()->count());
    }

    private function registeredProject(): Project
    {
        return Project::query()->create([
            'name' => 'Config-Projekt', 'remote' => 'git@git.example.test:acme/config.git',
            'control_branch' => 'refs/heads/main', 'project_identifier' => str_repeat('a', 32),
            'host_key_fingerprint' => 'SHA256:'.rtrim(base64_encode(random_bytes(32)), '='),
            'provisioning_status' => ProjectProvisioningStatus::PROVISIONED,
            'deploy_key_reference' => '/managed/config-key',
            'public_deploy_key' => "ssh-ed25519 fixture\n",
            'control_oid' => str_repeat('b', 64),
        ])->refresh();
    }

    private function projectConfigurationYaml(): string
    {
        return <<<'YAML'
        version: 1
        tickets_path: tickets
        ticket_validation_profile: generic_v1
        push_mode: manual
        auto_start_next: false
        dependency_satisfied_statuses:
          - done
        defaults:
          implementation_profile: codex-gpt-5.6-terra
          implementation_effort: medium
          reviewers:
            - profile: grok-cli-review
              effort: provider_default
        limits:
          max_fix_rounds: 3
          max_review_rounds: 4
          max_verification_rounds: 2
          max_agent_invocations: 20
          max_added_scope_paths: 12
          max_changed_files: 40
          max_changed_bytes: 2000000
          max_artifacts: 20
          max_artifact_bytes: 5000000
          max_total_artifact_bytes: 20000000
          max_provider_output_bytes: 2000000
          max_run_minutes: 180
        scope:
          auto_allow:
            - app/**
            - resources/**
            - tests/**
          require_approval:
            - AGENTS.md
            - CLAUDE.md
            - .ai6/**
            - tickets/**
        checks:
          before_review:
            - php-targeted
          final:
            - php-all
            - git-diff-check
        YAML;
    }

    private function stepUpRequest(User $actor): Request
    {
        $session = new Store('project-config-branch-test', new ArraySessionHandler(120));
        $session->setId('project-config-branch-'.bin2hex(random_bytes(8)));
        $session->start();
        $request = Request::create('/projects/control-branch', 'POST');
        $request->setLaravelSession($session);
        $this->app->make(StepUpGuard::class)->markSatisfied(
            $request,
            $actor,
            QueueControlBranchChange::STEP_UP_ACTION,
        );

        return $request;
    }

    private function validDraft(Project $project, int $actorId, ProjectConfiguration $configuration, string $hash): ProjectConfigDraft
    {
        $operation = $this->operation($project, $actorId);

        return ProjectConfigDraft::query()->create([
            'project_id' => $project->getKey(), 'control_operation_id' => $operation->id,
            'control_commit' => $project->control_oid, 'blob_sha' => hash('sha256', $operation->id),
            'control_generation' => $project->control_generation, 'state' => 'valid',
            'config_hash' => $hash, 'normalized_config' => $configuration->values,
            'validation_errors' => [], 'redaction_matches' => [],
        ]);
    }

    private function operation(Project $project, int $actorId): ControlOperation
    {
        return ControlOperation::query()->create([
            'id' => (string) Str::uuid(), 'project_id' => $project->getKey(), 'actor_id' => $actorId,
            'operation_type' => ControlOperationType::CONFIG_REFRESH, 'schema_version' => 1,
            'authorization_snapshot' => [], 'authorization_snapshot_jcs' => '{}',
            'expected_control_commit' => $project->control_oid,
            'operation_parameters_jcs' => json_encode(['config_path' => QueueProjectConfigRefresh::CONFIG_PATH], JSON_THROW_ON_ERROR),
            'request_hash' => hash('sha256', random_bytes(16)), 'phase' => ControlOperationPhase::CLAIMED,
            'state' => ControlOperationState::RUNNING, 'attempts' => 1, 'current_attempt_token' => 1,
            'started_at' => now(),
        ]);
    }
}
