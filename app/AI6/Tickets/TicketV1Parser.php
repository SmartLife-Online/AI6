<?php

namespace App\AI6\Tickets;

use App\AI6\Shared\Yaml\RestrictedYaml;
use App\AI6\Shared\Yaml\RestrictedYamlException;

final readonly class TicketV1Parser
{
    public const KNOWN_KEYS = [
        'schema', 'id', 'title', 'status', 'depends_on', 'kind', 'milestone', 'risk', 'files', 'spec_refs',
    ];

    public function __construct(private RestrictedYaml $yaml) {}

    /** @throws TicketParseException */
    public function parse(string $content): TicketDocument
    {
        if (preg_match('/\A---(?:\r\n|\n)(.*?)(?:\r\n|\n)---(?:\r\n|\n|\z)(.*)\z/s', $content, $matches) !== 1) {
            throw new TicketParseException([new TicketValidationError(
                'frontmatter_missing', 'frontmatter', 'Das V1-Frontmatter fehlt oder ist nicht abgeschlossen.',
            )]);
        }
        try {
            $frontmatter = $this->yaml->parseMapping($matches[1], self::KNOWN_KEYS);
        } catch (RestrictedYamlException $exception) {
            throw new TicketParseException(array_map(
                static fn ($error): TicketValidationError => new TicketValidationError($error->code, 'frontmatter', $error->message),
                $exception->errors,
            ));
        }

        $body = $matches[2];
        [$sections, $duplicates] = $this->sections($body);
        $files = isset($frontmatter['files']) && is_array($frontmatter['files'])
            ? array_values(array_filter($frontmatter['files'], 'is_string')) : [];
        $specRefs = isset($frontmatter['spec_refs']) && is_array($frontmatter['spec_refs'])
            ? array_values(array_filter($frontmatter['spec_refs'], 'is_string')) : [];

        return new TicketDocument(
            $frontmatter, $body, $sections, $duplicates,
            $this->ids($body, '/(?m)^- \[ \] \*\*(AC-\d{2})\*\*(?:\s|$)/'),
            $this->ids($body, '/(?m)^- \*\*(TC-\d{2})\*\*(?:\s|$)/'),
            $this->gateIds($sections['Manual and External Gates'] ?? '', 'MG'),
            $this->gateIds($sections['Manual and External Gates'] ?? '', 'EXT'),
            $files, $specRefs,
        );
    }

    /** @return array{array<string, string>, list<string>} */
    private function sections(string $body): array
    {
        preg_match_all('/(?m)^## ([^\r\n]+)\r?\n/', $body, $matches, PREG_OFFSET_CAPTURE);
        $sections = [];
        $duplicates = [];
        for ($index = 0, $count = count($matches[0]); $index < $count; $index++) {
            $name = $matches[1][$index][0];
            $start = $matches[0][$index][1] + strlen($matches[0][$index][0]);
            $end = $index + 1 < $count ? $matches[0][$index + 1][1] : strlen($body);
            if (array_key_exists($name, $sections)) {
                $duplicates[] = $name;
            } else {
                $sections[$name] = trim(substr($body, $start, $end - $start), "\r\n");
            }
        }

        return [$sections, $duplicates];
    }

    /** @return list<string> */
    private function ids(string $body, string $pattern): array
    {
        preg_match_all($pattern, $body, $matches);

        return $matches[1] ?? [];
    }

    /** @return list<string> */
    private function gateIds(string $section, string $kind): array
    {
        preg_match_all('/(?m)^- \*\*('.$kind.'-\d{2})\*\*(?:\s|$)/', $section, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
