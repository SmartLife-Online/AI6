<?php

namespace App\AI6\Git;

use JsonSerializable;

final readonly class ReviewSubject implements JsonSerializable
{
    public function __construct(
        public ReviewSubjectKind $kind,
        public string $baseOid,
        public string $sourceOid,
        public ?string $ref = null,
        public ?string $sourceRunId = null,
        public ?string $expectedTreeOid = null,
        public ?string $expectedDiffHash = null,
    ) {
        foreach ([$baseOid, $sourceOid] as $oid) {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $oid) !== 1) {
                throw new ReviewSubjectException('source_oid_invalid');
            }
        }
        if ($kind === ReviewSubjectKind::MANAGED_BRANCH) {
            if (! is_string($ref) || $ref === '') {
                throw new ReviewSubjectException('managed_branch_ref_missing');
            }
            // The grammar comes from the one place the hardened runner reads it
            // from; the configured allowlist on top stays with GitRemotePolicy
            // and is applied when the ref is actually resolved.
            if (! GitRefName::valid($ref)) {
                throw new ReviewSubjectException('managed_branch_ref_invalid');
            }
        } elseif ($ref !== null) {
            throw new ReviewSubjectException('source_ref_not_allowed');
        }
        if (in_array($kind, [ReviewSubjectKind::VALIDATED_PATCH, ReviewSubjectKind::CHECKPOINT], true)) {
            if (! is_string($sourceRunId) || ! ManagedProjectPath::validRunIdentifier($sourceRunId)
                || ! self::sha($expectedTreeOid) || ! self::sha($expectedDiffHash)) {
                throw new ReviewSubjectException('stored_source_binding_incomplete');
            }
        } elseif ($sourceRunId !== null || $expectedTreeOid !== null || $expectedDiffHash !== null) {
            throw new ReviewSubjectException('stored_source_binding_not_allowed');
        }
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        $value = [
            'schema' => 'ai6.review-subject.v1',
            'kind' => $this->kind->value,
            'base_oid' => $this->baseOid,
            'source_oid' => $this->sourceOid,
        ];
        foreach (['ref' => $this->ref, 'source_run_id' => $this->sourceRunId, 'tree_oid' => $this->expectedTreeOid, 'diff_hash' => $this->expectedDiffHash] as $key => $entry) {
            if ($entry !== null) {
                $value[$key] = $entry;
            }
        }

        return $value;
    }

    private static function sha(?string $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
