<?php

namespace App\AI6\Git;

use App\AI6\Shared\Json\RestrictedJsonDecoder;
use App\AI6\Shared\Redaction\RedactionContext;

/** Closed, canonical serialization of the server-bound review subject. */
final readonly class ReviewSubjectReference
{
    public function __construct(
        private CanonicalJson $json,
        private RestrictedJsonDecoder $decoder,
    ) {}

    public function encode(ReviewSubject $subject): string
    {
        return $this->json->normalizeAndEncode($subject->jsonSerialize());
    }

    public function decode(string $reference, RedactionContext $context): ReviewSubject
    {
        try {
            $value = $this->decoder->decode($reference, $context);
        } catch (\Throwable) {
            throw new ReviewSubjectException($this->rejectedInputReason($reference));
        }
        if (($value['schema'] ?? null) !== 'ai6.review-subject.v1'
            || array_diff(array_keys($value), ['schema', 'kind', 'base_oid', 'source_oid', 'ref', 'source_run_id', 'tree_oid', 'diff_hash']) !== []) {
            throw new ReviewSubjectException('source_reference_invalid');
        }
        $kind = ReviewSubjectKind::tryFrom(is_string($value['kind'] ?? null) ? $value['kind'] : '');
        if (! $kind instanceof ReviewSubjectKind) {
            throw new ReviewSubjectException('source_kind_forbidden');
        }

        return new ReviewSubject(
            $kind,
            is_string($value['base_oid'] ?? null) ? $value['base_oid'] : '',
            is_string($value['source_oid'] ?? null) ? $value['source_oid'] : '',
            is_string($value['ref'] ?? null) ? $value['ref'] : null,
            is_string($value['source_run_id'] ?? null) ? $value['source_run_id'] : null,
            is_string($value['tree_oid'] ?? null) ? $value['tree_oid'] : null,
            is_string($value['diff_hash'] ?? null) ? $value['diff_hash'] : null,
        );
    }

    private function rejectedInputReason(string $reference): string
    {
        if (preg_match('/\Ahttps?:\/\//i', $reference) === 1) {
            return 'pull_request_url_forbidden';
        }
        if (str_contains($reference, '\\') || str_starts_with($reference, '/') || preg_match('~\A[A-Za-z]:[\\/]~D', $reference) === 1) {
            return 'free_working_directory_forbidden';
        }
        if (str_contains($reference, ',') || str_contains($reference, "\n")) {
            return 'free_file_selection_forbidden';
        }

        return 'source_kind_forbidden';
    }
}
