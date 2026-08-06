<?php

namespace App\AI6\Git;

enum ControlOperationType: string
{
    case DEPLOY_KEY_PROVISION = 'deploy_key_provision';

    /** @var array<string, list<string>> */
    public const PARAMETER_FIELDS = [
        'deploy_key_provision' => ['algorithm'],
    ];

    /**
     * @param  array<string, scalar|null>  $values
     * @return array<string, scalar|null>
     */
    public function parameters(array $values): array
    {
        $parameters = [];
        foreach (self::PARAMETER_FIELDS[$this->value] as $field) {
            if (! array_key_exists($field, $values)) {
                throw new ControlOperationConflict(sprintf('Operation parameter %s is missing.', $field));
            }

            $parameters[$field] = $values[$field];
        }

        if (count($parameters) !== count($values)) {
            throw new ControlOperationConflict('The operation contains an unknown parameter.');
        }

        return $parameters;
    }
}
