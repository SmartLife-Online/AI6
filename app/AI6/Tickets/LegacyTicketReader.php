<?php

namespace App\AI6\Tickets;

use App\AI6\Shared\Yaml\RestrictedYaml;
use App\AI6\Shared\Yaml\RestrictedYamlException;

final readonly class LegacyTicketReader
{
    public function __construct(private RestrictedYaml $yaml) {}

    public function canRead(string $content): bool
    {
        return preg_match('/\A\s*```ya?ml\s*\r?\n.*\r?\n```\s*\z/is', $content) === 1;
    }

    /** @throws TicketParseException */
    public function read(string $content): LegacyTicketDocument
    {
        if (preg_match('/\A\s*```ya?ml\s*\r?\n(.*)\r?\n```\s*\z/is', $content, $matches) !== 1) {
            throw new TicketParseException([new TicketValidationError(
                'legacy_format_invalid', 'legacy', 'Das Legacy-Dokument besteht nicht aus genau einem YAML-Codeblock.',
            )]);
        }
        try {
            $fields = $this->yaml->parseMapping($matches[1]);
        } catch (RestrictedYamlException $exception) {
            throw new TicketParseException(array_map(
                static fn ($error): TicketValidationError => new TicketValidationError($error->code, 'legacy', $error->message),
                $exception->errors,
            ));
        }

        return new LegacyTicketDocument($fields, $matches[1]);
    }
}
