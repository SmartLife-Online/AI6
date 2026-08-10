<?php

namespace Tests\Feature\Projects;

use App\AI6\Projects\ControlGeneration;
use App\AI6\Projects\Models\Project;
use Tests\TestCase;

final class ControlGenerationTest extends TestCase
{
    public function test_a_recorded_generation_is_current_only_while_it_matches_the_project(): void
    {
        $project = new Project;
        $project->forceFill(['control_generation' => 12]);
        $generation = new ControlGeneration;

        self::assertTrue($generation->isCurrent($project, 12));
        self::assertFalse($generation->isCurrent($project, 11));
        self::assertFalse($generation->isCurrent($project, 13));
    }
}
