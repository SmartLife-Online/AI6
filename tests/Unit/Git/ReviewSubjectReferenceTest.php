<?php

namespace Tests\Unit\Git;

use App\AI6\Git\ReviewSubject;
use App\AI6\Git\ReviewSubjectException;
use App\AI6\Git\ReviewSubjectKind;
use App\AI6\Git\ReviewSubjectReference;
use App\AI6\Shared\Redaction\RedactionContext;
use Tests\TestCase;

final class ReviewSubjectReferenceTest extends TestCase
{
    public function test_every_published_source_kind_round_trips_through_the_canonical_reference(): void
    {
        $references = $this->app->make(ReviewSubjectReference::class);
        $base = str_repeat('a', 64);
        $source = str_repeat('b', 64);
        $runId = '12345678-1234-4234-8234-123456789abc';

        $subjects = [
            new ReviewSubject(ReviewSubjectKind::MANAGED_BRANCH, $base, $source, 'refs/heads/review'),
            new ReviewSubject(ReviewSubjectKind::COMMIT_RANGE, $base, $source),
            new ReviewSubject(ReviewSubjectKind::SINGLE_COMMIT, $base, $source),
            new ReviewSubject(ReviewSubjectKind::VALIDATED_PATCH, $base, $source, sourceRunId: $runId, expectedTreeOid: str_repeat('c', 64), expectedDiffHash: str_repeat('d', 64)),
            new ReviewSubject(ReviewSubjectKind::CHECKPOINT, $base, $source, sourceRunId: $runId, expectedTreeOid: str_repeat('c', 64), expectedDiffHash: str_repeat('d', 64)),
        ];

        foreach ($subjects as $subject) {
            self::assertEquals($subject, $references->decode(
                $references->encode($subject),
                new RedactionContext('project', 'run', 'review-subject-test'),
            ));
        }
    }

    public function test_unbound_source_forms_are_rejected_with_distinct_reasons(): void
    {
        $references = $this->app->make(ReviewSubjectReference::class);
        $context = new RedactionContext('project', 'run', 'review-subject-test');
        $cases = [
            'https://example.invalid/pull/7' => 'pull_request_url_forbidden',
            'C:\\unmanaged\\checkout' => 'free_working_directory_forbidden',
            'app/A.php,app/B.php' => 'free_file_selection_forbidden',
        ];

        foreach ($cases as $input => $reason) {
            try {
                $references->decode($input, $context);
                self::fail('An unbound review source was accepted.');
            } catch (ReviewSubjectException $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }
    }

    public function test_a_structured_fifth_source_kind_is_rejected_without_fallback(): void
    {
        try {
            $this->app->make(ReviewSubjectReference::class)->decode(
                '{"base_oid":"'.str_repeat('a', 64).'","kind":"pull_request","schema":"ai6.review-subject.v1","source_oid":"'.str_repeat('b', 64).'"}',
                new RedactionContext('project', 'run', 'review-subject-test'),
            );
            self::fail('An unknown structured review source was accepted.');
        } catch (ReviewSubjectException $exception) {
            self::assertSame('source_kind_forbidden', $exception->reason);
        }
    }
}
