<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbGratitude;

/**
 * Domain-level validation failure raised by GratitudeService.
 *
 * A DomainException (not a generic RuntimeException) so the HTTP layer added in
 * a later task can map it to a 4xx (422 Unprocessable Entity) rather than a 500 —
 * the same "bad caller input, not a server fault" signal the reference controllers
 * express with abort(422)/ValidationException.
 */
class GratitudeException extends \DomainException
{
    /**
     * The recipient staff id does not resolve to a real teammate in this tenant.
     * Raised BEFORE any shoutout row is written, so a bad recipient never persists.
     */
    public static function unknownRecipient(string $recipientStaffId): self
    {
        return new self("Unknown recipient: no active staff member '{$recipientStaffId}' in this tenant.");
    }
}
