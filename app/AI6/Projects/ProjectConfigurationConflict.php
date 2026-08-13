<?php

namespace App\AI6\Projects;

use RuntimeException;

final class ProjectConfigurationConflict extends RuntimeException
{
    public function __construct(public readonly string $conflict, string $message)
    {
        parent::__construct($message);
    }
}
