<?php

namespace App\AI6\Shared\Markdown;

use RuntimeException;

final class AllowedHtmlPolicy
{
    /** @var list<string> */
    private const ELEMENTS = [
        'p', 'br', 'hr', 'em', 'strong', 'blockquote', 'ul', 'ol', 'li',
        'pre', 'code', 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /** @var array<string, list<string>> */
    private const ATTRIBUTES = [
        'a' => ['href', 'title'],
        'code' => ['class'],
        'ol' => ['start'],
    ];

    public function sanitize(string $html): string
    {
        $allowedTags = implode('', array_map(
            static fn (string $element): string => '<'.$element.'>',
            self::ELEMENTS,
        ));
        $html = strip_tags($html, $allowedTags);
        $sanitized = preg_replace_callback(
            '/<(?<closing>\/?)(?<tag>[a-z][a-z0-9]*)(?<attributes>[^>]*)>/i',
            fn (array $match): string => $this->sanitizeElement($match),
            $html,
        );

        if ($sanitized === null) {
            throw new RuntimeException('The safe HTML policy could not be evaluated.');
        }

        return $sanitized;
    }

    /** @param array<string|int, string> $match */
    private function sanitizeElement(array $match): string
    {
        $tag = strtolower($match['tag']);

        if (! in_array($tag, self::ELEMENTS, true)) {
            return '';
        }

        if ($match['closing'] === '/') {
            return in_array($tag, ['br', 'hr'], true) ? '' : '</'.$tag.'>';
        }

        $attributes = $this->sanitizeAttributes($tag, $match['attributes']);

        return '<'.$tag.$attributes.'>';
    }

    private function sanitizeAttributes(string $tag, string $source): string
    {
        $allowed = self::ATTRIBUTES[$tag] ?? [];

        if ($allowed === []) {
            return '';
        }

        $count = preg_match_all(
            '/\s+([a-z][a-z0-9:-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/i',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        if ($count === false) {
            throw new RuntimeException('The safe HTML attribute policy could not be evaluated.');
        }

        $attributes = [];

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            if (! in_array($name, $allowed, true) || array_key_exists($name, $attributes)) {
                continue;
            }

            $doubleQuotedValue = $match[2] ?? '';
            $singleQuotedValue = $match[3] ?? '';
            $unquotedValue = $match[4] ?? '';
            $rawValue = $doubleQuotedValue !== ''
                ? $doubleQuotedValue
                : ($singleQuotedValue !== '' ? $singleQuotedValue : $unquotedValue);
            $value = html_entity_decode($rawValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (! $this->allowsAttributeValue($name, $value)) {
                continue;
            }

            $attributes[$name] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }

        $rendered = '';

        foreach ($attributes as $name => $value) {
            $rendered .= ' '.$name.'="'.$value.'"';
        }

        return $rendered;
    }

    private function allowsAttributeValue(string $name, string $value): bool
    {
        return match ($name) {
            'href' => $this->allowsLinkTarget($value),
            'class' => preg_match('/\Alanguage-[A-Za-z0-9_-]{1,64}\z/D', $value) === 1,
            'start' => preg_match('/\A[1-9][0-9]{0,8}\z/D', $value) === 1,
            'title' => preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1,
            default => false,
        };
    }

    private function allowsLinkTarget(string $target): bool
    {
        $target = trim($target);

        if ($target === '' || preg_match('/[\x00-\x20\x7F]/', $target) === 1) {
            return false;
        }

        $scheme = parse_url($target, PHP_URL_SCHEME);

        return ! is_string($scheme)
            || in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }
}
