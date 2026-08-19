<?php

namespace App\AI6\Checks;

/**
 * The four distinguishable outcomes of a check (plan §15, AI6-021).
 *
 * None of them is ever mapped onto another: a missing tool and a timeout stay
 * visible as themselves instead of degrading into a plain failure or, worse,
 * into a green result.
 */
enum CheckResultState: string
{
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case TIMED_OUT = 'timed_out';
    case TOOL_UNAVAILABLE = 'tool_unavailable';
}
