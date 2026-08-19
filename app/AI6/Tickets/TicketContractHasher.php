<?php

namespace App\AI6\Tickets;

use App\AI6\Git\CanonicalJson;
use Normalizer;
use RuntimeException;
use stdClass;

final readonly class TicketContractHasher
{
    private const DOMAIN = 'AI6-TICKET-CONTRACT-V1';

    private const CONTRACT_KEYS = [
        'schema', 'id', 'title', 'depends_on', 'kind', 'milestone', 'risk', 'files', 'spec_refs',
    ];

    public function __construct(private CanonicalJson $canonicalJson) {}

    public function hash(TicketDocument $document): string
    {
        $frontmatter = new stdClass;
        foreach (self::CONTRACT_KEYS as $key) {
            if (array_key_exists($key, $document->frontmatter)) {
                $frontmatter->{$key} = $document->frontmatter[$key];
            }
        }
        $json = $this->canonicalJson->normalizeAndEncode($frontmatter);
        $body = preg_replace('/\r\n|\r/', "\n", $document->body);
        if (! is_string($body)) {
            throw new RuntimeException('Ticket body line endings could not be normalized.');
        }
        // The AI6-owned `## Recorded Scope` section is documentation, not
        // contract (plan §5.2 step 4, TKT-012): it is removed with its heading
        // and its content up to the next `##` heading before the body is
        // hashed, so writing or updating it never looks like a contract change
        // and never invalidates review evidence. Line-ending normalization can
        // neither create nor destroy that heading, so doing it first yields the
        // same bytes as the plan's order.
        $body = self::stripRecordedScope($body);
        $body = Normalizer::normalize($body, Normalizer::FORM_C);
        if (! is_string($body)) {
            throw new RuntimeException('Ticket body Unicode could not be normalized.');
        }
        $body = rtrim($body, "\n")."\n";
        $input = self::DOMAIN."\0".pack('J', strlen($json)).$json.pack('J', strlen($body)).$body;

        return hash('sha256', $input);
    }

    /** Remove the AI6-owned `## Recorded Scope` section from an LF-normalized body. */
    private static function stripRecordedScope(string $body): string
    {
        if (! str_contains($body, '## Recorded Scope')) {
            return $body;
        }
        $kept = [];
        $inRecordedScope = false;
        foreach (explode("\n", $body) as $line) {
            if ($inRecordedScope) {
                if (! self::isSectionHeading($line)) {
                    continue;
                }
                $inRecordedScope = false;
            }
            if (rtrim($line, " \t") === '## Recorded Scope') {
                $inRecordedScope = true;

                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /** A `##` heading ends the AI6-owned section; a deeper `###` heading belongs to it. */
    private static function isSectionHeading(string $line): bool
    {
        return str_starts_with($line, '## ') || rtrim($line, " \t") === '##';
    }
}
