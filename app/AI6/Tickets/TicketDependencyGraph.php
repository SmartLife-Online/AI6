<?php

namespace App\AI6\Tickets;

final class TicketDependencyGraph
{
    /** @param array<string, list<string>> $dependencies
     * @return list<TicketValidationError>
     */
    public function validate(array $dependencies): array
    {
        ksort($dependencies, SORT_STRING);
        $errors = [];
        foreach ($dependencies as $id => $items) {
            foreach ($items as $dependency) {
                if ($dependency === $id) {
                    $errors[] = new TicketValidationError(
                        'dependency_self_reference', $id.'.depends_on', 'Das Ticket hängt von sich selbst ab.',
                    );
                } elseif (! array_key_exists($dependency, $dependencies)) {
                    $errors[] = new TicketValidationError(
                        'dependency_missing', $id.'.depends_on', 'Eine deklarierte Abhängigkeit fehlt im Ticketbestand.',
                    );
                }
            }
        }

        $state = [];
        foreach (array_keys($dependencies) as $id) {
            $this->visit($id, $dependencies, $state, $errors);
        }

        return $this->unique($errors);
    }

    /** @param array<string, list<string>> $dependencies
     * @param  array<string, int>  $state
     * @param  list<TicketValidationError>  $errors
     */
    private function visit(string $id, array $dependencies, array &$state, array &$errors): void
    {
        if (($state[$id] ?? 0) === 2) {
            return;
        }
        if (($state[$id] ?? 0) === 1) {
            $errors[] = new TicketValidationError(
                'dependency_cycle', $id.'.depends_on', 'Der Abhängigkeitsgraph enthält einen Zyklus.',
            );

            return;
        }
        $state[$id] = 1;
        $items = $dependencies[$id] ?? [];
        sort($items, SORT_STRING);
        foreach ($items as $dependency) {
            if (array_key_exists($dependency, $dependencies) && $dependency !== $id) {
                $this->visit($dependency, $dependencies, $state, $errors);
            }
        }
        $state[$id] = 2;
    }

    /** @param list<TicketValidationError> $errors
     * @return list<TicketValidationError>
     */
    private function unique(array $errors): array
    {
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error->code."\0".$error->field] = $error;
        }

        return array_values($unique);
    }
}
