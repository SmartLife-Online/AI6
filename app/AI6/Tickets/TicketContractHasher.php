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
        $body = Normalizer::normalize($body, Normalizer::FORM_C);
        if (! is_string($body)) {
            throw new RuntimeException('Ticket body Unicode could not be normalized.');
        }
        $body = rtrim($body, "\n")."\n";
        $input = self::DOMAIN."\0".pack('J', strlen($json)).$json.pack('J', strlen($body)).$body;

        return hash('sha256', $input);
    }
}
