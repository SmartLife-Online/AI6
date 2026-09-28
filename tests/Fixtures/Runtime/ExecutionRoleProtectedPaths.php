<?php

namespace Tests\Fixtures\Runtime;

/** Independent OCI replacement expectations shared by static and native tests. */
final class ExecutionRoleProtectedPaths
{
    public const MASKED = ['/proc/acpi', '/proc/asound', '/proc/interrupts', '/proc/kcore', '/proc/keys', '/proc/latency_stats', '/proc/sched_debug', '/proc/scsi', '/proc/timer_list', '/proc/timer_stats', '/sys/devices/virtual/powercap', '/sys/firmware'];

    public const READONLY = ['/proc/bus', '/proc/fs', '/proc/irq', '/proc/sys', '/proc/sysrq-trigger'];
}
