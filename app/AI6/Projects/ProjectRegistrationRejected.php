<?php

namespace App\AI6\Projects;

use RuntimeException;
use Throwable;

final class ProjectRegistrationRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('Project registration rejected.', previous: $previous);
    }
}
