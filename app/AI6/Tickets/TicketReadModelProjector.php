<?php

namespace App\AI6\Tickets;

use App\AI6\Projects\TicketDocumentState;

final readonly class TicketReadModelProjector
{
    public function __construct(
        private TicketV1Parser $parser,
        private LegacyTicketReader $legacy,
        private GenericV1TicketValidator $generic,
        private Ai6DetailV1TicketValidator $detail,
        private TicketContractHasher $hasher,
    ) {}

    public function project(
        string $content,
        string $relativePath,
        TicketValidationProfile $profile,
        ?TicketValidationProfile $requiredProfile = null,
    ): TicketProjection {
        $requiredProfile ??= $profile;
        if ($this->legacy->canRead($content)) {
            try {
                $this->legacy->read($content);
                $errors = [new TicketValidationError(
                    'legacy_format', 'legacy', 'Das Dokument verwendet das schreibgeschützte Legacy-Format.',
                )];
            } catch (TicketParseException $exception) {
                $errors = $exception->errors;
            }

            return $this->invalid($profile, $requiredProfile, $errors);
        }

        try {
            $document = $this->parser->parse($content);
        } catch (TicketParseException $exception) {
            return $this->invalid($profile, $requiredProfile, $exception->errors);
        }

        $errors = match ($profile) {
            TicketValidationProfile::GENERIC_V1 => $this->generic->validate($document, $relativePath),
            TicketValidationProfile::AI6_DETAIL_V1 => $this->detail->validate($document, $relativePath),
        };
        if ($errors !== []) {
            return $this->invalid($profile, $requiredProfile, $errors, $document);
        }

        $blockers = $profile === $requiredProfile
            ? []
            : [TicketSourceBlockers::VALIDATION_PROFILE_MISMATCH];

        return new TicketProjection(
            TicketDocumentState::VALID,
            $profile,
            $this->hasher->hash($document),
            [],
            $blockers,
            $document,
        );
    }

    /** @param non-empty-list<TicketValidationError> $errors */
    private function invalid(
        TicketValidationProfile $profile,
        TicketValidationProfile $requiredProfile,
        array $errors,
        ?TicketDocument $document = null,
    ): TicketProjection {
        $blockers = [TicketSourceBlockers::INVALID];
        if ($profile !== $requiredProfile) {
            $blockers[] = TicketSourceBlockers::VALIDATION_PROFILE_MISMATCH;
        }

        return new TicketProjection(
            TicketDocumentState::INVALID,
            $profile,
            null,
            $errors,
            TicketSourceBlockers::canonicalize($blockers),
            $document,
        );
    }
}
