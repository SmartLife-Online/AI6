<?php

namespace App\AI6\Projects;

final class ProjectConfigurationDiff
{
    /** @return list<array{path: string, before: mixed, after: mixed}> */
    public function between(ProjectConfiguration $before, ProjectConfiguration $after): array
    {
        $left = $this->flatten($before->values);
        $right = $this->flatten($after->values);
        $paths = array_values(array_unique([...array_keys($left), ...array_keys($right)]));
        sort($paths, SORT_STRING);

        $diff = [];
        foreach ($paths as $path) {
            $old = $left[$path] ?? null;
            $new = $right[$path] ?? null;
            if ($old !== $new) {
                $diff[] = ['path' => $path, 'before' => $old, 'after' => $new];
            }
        }

        return $diff;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $result += $this->flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }

        return $result;
    }
}
