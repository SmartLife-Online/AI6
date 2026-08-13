<?php

namespace App\AI6\Projects;

enum ProjectConfigurationSource: string
{
    case SERVER_DEFAULTS = 'server_defaults';
    case APPROVED_SNAPSHOT = 'approved_snapshot';
}
