<?php

namespace App\AI6\Projects;

enum ProjectRole: string
{
    case ADMIN = 'admin';
    case VIEWER = 'viewer';
    case OPERATOR = 'operator';
    case APPROVER = 'approver';
}
