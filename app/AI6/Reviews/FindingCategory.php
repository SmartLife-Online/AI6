<?php

namespace App\AI6\Reviews;

enum FindingCategory: string
{
    case CONTRACT = 'contract';
    case CORRECTNESS = 'correctness';
    case SECURITY = 'security';
    case TESTS = 'tests';
    case ARCHITECTURE = 'architecture';
    case DATABASE = 'database';
    case CONCURRENCY = 'concurrency';
    case PERFORMANCE = 'performance';
    case SCOPE = 'scope';
    case DOCUMENTATION = 'documentation';
    case OTHER = 'other';
}
