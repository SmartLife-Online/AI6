<?php

$configuredMemoryLimit = ini_get('memory_limit');
$normalizedMemoryLimit = strtolower(trim($configuredMemoryLimit));

if ($normalizedMemoryLimit !== '-1') {
    $unit = substr($normalizedMemoryLimit, -1);
    $numericLimit = (int) $normalizedMemoryLimit;
    $memoryLimitBytes = match ($unit) {
        'g' => $numericLimit * 1024 * 1024 * 1024,
        'm' => $numericLimit * 1024 * 1024,
        'k' => $numericLimit * 1024,
        default => $numericLimit,
    };

    if ($memoryLimitBytes < 512 * 1024 * 1024 && ini_set('memory_limit', '512M') === false) {
        throw new RuntimeException('PHPStan requires at least a 512M analysis memory limit.');
    }
}
