<?php

namespace App\AI6\Shared\Process;

use RuntimeException;

/** Unreadable output cannot provide resource-limit evidence. */
final class ProcessInventoryUnavailableException extends RuntimeException {}
