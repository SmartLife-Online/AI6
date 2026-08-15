<?php

namespace App\AI6\Runs;

use App\AI6\Agents\AgentInputLimits;
use InvalidArgumentException;
use JsonSerializable;

final readonly class ApprovalLimits implements JsonSerializable
{
    /** @param array<string, int> $values */
    private function __construct(public array $values) {}

    /** @param array<string, mixed> $requested */
    public static function fromConfiguredValues(array $requested, AgentInputLimits $inputLimits): self
    {
        $maxima = config('ai6.project_config.server_maxima');
        if (! is_array($maxima) || array_is_list($maxima)) {
            throw new InvalidArgumentException('Die Servermaxima sind nicht verfügbar.');
        }
        $values = [];
        foreach ($maxima as $name => $maximum) {
            $value = $requested[$name] ?? null;
            if (! is_string($name) || ! is_int($maximum) || ! is_int($value) || $value < 1 || $value > $maximum) {
                throw new InvalidArgumentException('Das Limit '.$name.' ist ungültig oder überschreitet das Servermaximum.');
            }
            $values[$name] = $value;
        }
        $values += [
            'max_instruction_files' => $inputLimits->maxInstructionFiles,
            'max_instruction_file_bytes' => $inputLimits->maxInstructionFileBytes,
            'max_instruction_total_bytes' => $inputLimits->maxInstructionTotalBytes,
            'max_instruction_import_depth' => $inputLimits->maxInstructionImportDepth,
            'max_prompt_input_bytes' => $inputLimits->maxPromptInputBytes,
        ];
        ksort($values, SORT_STRING);

        return new self($values);
    }

    /** @return array<string, int> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
