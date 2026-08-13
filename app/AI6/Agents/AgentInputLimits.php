<?php

namespace App\AI6\Agents;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictPositiveIntegerParser;

final readonly class AgentInputLimits
{
    public function __construct(
        public int $maxInstructionFiles,
        public int $maxInstructionFileBytes,
        public int $maxInstructionTotalBytes,
        public int $maxInstructionImportDepth,
        public int $maxPromptInputBytes,
    ) {}

    public static function fromConfiguredValues(StrictPositiveIntegerParser $parser): self
    {
        $values = config('ai6.agent_input_limits');
        if (! is_array($values) || array_is_list($values) || array_keys($values) !== [
            'max_instruction_files',
            'max_instruction_file_bytes',
            'max_instruction_total_bytes',
            'max_instruction_import_depth',
            'max_prompt_input_bytes',
        ]) {
            throw new ConfigurationException('Configuration key ai6.agent_input_limits must contain the canonical limit fields.');
        }

        return new self(
            self::parse($parser, 'ai6.agent_input_limits.max_instruction_files', $values['max_instruction_files']),
            self::parse($parser, 'ai6.agent_input_limits.max_instruction_file_bytes', $values['max_instruction_file_bytes']),
            self::parse($parser, 'ai6.agent_input_limits.max_instruction_total_bytes', $values['max_instruction_total_bytes']),
            self::parse($parser, 'ai6.agent_input_limits.max_instruction_import_depth', $values['max_instruction_import_depth']),
            self::parse($parser, 'ai6.agent_input_limits.max_prompt_input_bytes', $values['max_prompt_input_bytes']),
        );
    }

    private static function parse(StrictPositiveIntegerParser $parser, string $key, mixed $value): int
    {
        $parsed = $parser->parse($key, $value, PHP_INT_MAX);
        if ($parsed instanceof ConfigurationViolation) {
            throw new ConfigurationException($parsed->message);
        }

        return $parsed;
    }
}
