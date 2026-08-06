<?php

namespace Tests\Feature\Projects;

use App\AI6\Git\Models\ControlOperation;
use App\AI6\Projects\ProjectRole;
use Illuminate\Support\Str;
use Tests\Feature\Git\ControlOperationTestCase;

final class ControlOperationAuthorizationTest extends ControlOperationTestCase
{
    public function test_provisioning_and_recovery_require_a_global_project_administrator(): void
    {
        $projectAdministrator = $this->createUser();
        $viewer = $this->createUser();
        $project = $this->registeredProject($projectAdministrator);
        $this->addMembership($viewer, $project, ProjectRole::VIEWER);

        $this->actingAs($viewer)
            ->post(route('projects.deploy-key.provision', $project), ['operation_id' => (string) Str::uuid()])
            ->assertForbidden();
        $this->actingAs($projectAdministrator)
            ->post(route('projects.deploy-key.provision', $project), ['operation_id' => (string) Str::uuid()])
            ->assertForbidden();
        $projectAdministrator->forceFill(['is_global_admin' => true])->save();
        $this->actingAs($projectAdministrator->fresh())
            ->post(route('projects.deploy-key.provision', $project), ['operation_id' => (string) Str::uuid()])
            ->assertRedirect();

        self::assertSame(1, ControlOperation::query()->count());
        self::assertTrue($projectAdministrator->fresh()->can('decideRecovery', $project));
    }

    public function test_no_controller_or_livewire_component_executes_git_or_processes_and_only_one_process_wrapper_exists(): void
    {
        $processStarters = [];
        foreach ($this->productionPhpFiles() as $path) {
            $source = file_get_contents($path);
            self::assertIsString($source);
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            $isControllerOrComponent = str_ends_with($path, 'Controller.php')
                || str_contains($relative, '/Livewire/')
                || str_contains($relative, '/Components/');
            if ($isControllerOrComponent) {
                foreach ([
                    'ControlProcessRunner',
                    'HardenedGitRunner',
                    'RunningControlProcess',
                    'BlockedControlProcess',
                    'Symfony\\Component\\Process',
                    'Process::',
                    'startBlocked(',
                    'proc_open(',
                    'shell_exec(',
                ] as $forbidden) {
                    self::assertStringNotContainsString($forbidden, $source, $relative.' contains '.$forbidden);
                }
            }

            if (str_contains($source, 'new Process(')
                || str_contains($source, 'proc_open(')
                || str_contains($source, 'shell_exec(')) {
                $processStarters[] = $relative;
            }
        }

        self::assertSame(['app/AI6/Shared/Process/ControlProcessRunner.php'], $processStarters);
    }

    /** @return list<string> */
    private function productionPhpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
