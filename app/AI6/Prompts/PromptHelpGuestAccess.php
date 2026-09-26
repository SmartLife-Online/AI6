<?php

namespace App\AI6\Prompts;

use App\AI6\Shared\Config\ConfigurationException;
use App\AI6\Shared\Config\ConfigurationViolation;
use App\AI6\Shared\Config\StrictBooleanParser;

/**
 * The one evaluation of the guest access switch for the manual prompt help.
 * A missing or empty value keeps the login requirement; an invalid value is a
 * configuration violation that names the key, never the value.
 */
final readonly class PromptHelpGuestAccess
{
    public const ENVIRONMENT_KEY = 'AI6_PROMPT_HELP_GUEST_ACCESS';

    public function __construct(private StrictBooleanParser $parser) {}

    public function enabled(): bool
    {
        $value = config('ai6.manual_prompt_help.guest_access');
        if ($value === null || $value === '') {
            return false;
        }

        $parsed = $this->parser->parse(self::ENVIRONMENT_KEY, $value);
        if ($parsed instanceof ConfigurationViolation) {
            throw new ConfigurationException($parsed->message);
        }

        return $parsed;
    }
}
