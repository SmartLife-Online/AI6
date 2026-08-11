<?php

namespace App\AI6\Shared\Yaml;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class RestrictedYaml
{
    /** @param list<string>|null $knownKeys
     * @return array<string, mixed>
     *
     * @throws RestrictedYamlException
     */
    public function parseMapping(string $yaml, ?array $knownKeys = null): array
    {
        $feature = $this->forbiddenFeature($yaml);
        if ($feature !== null) {
            throw new RestrictedYamlException([new YamlError(
                'yaml_'.$feature.'_forbidden',
                'Die YAML-Struktur verwendet eine nicht erlaubte Funktion.',
            )]);
        }

        try {
            $decoded = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException) {
            throw new RestrictedYamlException([new YamlError(
                'yaml_parse_error',
                'Das YAML ist syntaktisch ungültig oder enthält doppelte Schlüssel.',
            )]);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RestrictedYamlException([new YamlError(
                'yaml_mapping_required',
                'Das YAML muss ein Mapping auf oberster Ebene sein.',
            )]);
        }
        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw new RestrictedYamlException([new YamlError(
                    'yaml_string_key_required',
                    'YAML-Schlüssel müssen Zeichenketten sein.',
                )]);
            }
        }
        if ($knownKeys !== null && array_diff(array_keys($decoded), $knownKeys) !== []) {
            throw new RestrictedYamlException([new YamlError(
                'yaml_unknown_key',
                'Das YAML enthält mindestens einen unbekannten Schlüssel.',
            )]);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function forbiddenFeature(string $yaml): ?string
    {
        foreach (preg_split('/\r\n|\n|\r/', $yaml) ?: [] as $line) {
            $plain = $this->outsideQuotedText($line);
            if (preg_match('/^\s*<<\s*:/', $plain) === 1) {
                return 'merge_key';
            }
            if (preg_match('/(?:^|[\s:\[,{-])&[^\s,\]}]+/', $plain) === 1) {
                return 'anchor';
            }
            if (preg_match('/(?:^|[\s:\[,{-])\*[^\s,\]}]+/', $plain) === 1) {
                return 'alias';
            }
            if (preg_match('/(?:^|[\s:\[,{-])![^\s,\]}]+/', $plain) === 1) {
                return 'tag';
            }
        }

        return null;
    }

    private function outsideQuotedText(string $line): string
    {
        $output = '';
        $quote = null;
        $escaped = false;
        $length = strlen($line);
        for ($index = 0; $index < $length; $index++) {
            $character = $line[$index];
            if ($quote === '"') {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quote = null;
                }
                $output .= ' ';

                continue;
            }
            if ($quote === "'") {
                if ($character !== "'") {
                    $output .= ' ';

                    continue;
                }
                if ($index + 1 < $length && $line[$index + 1] === "'") {
                    $output .= '  ';
                    $index++;

                    continue;
                }
                $quote = null;
                $output .= ' ';

                continue;
            }
            if ($character === '#') {
                break;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $output .= ' ';

                continue;
            }
            $output .= $character;
        }

        return $output;
    }
}
