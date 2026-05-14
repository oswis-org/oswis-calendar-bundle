<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Exception;

use OswisOrg\OswisCoreBundle\Exceptions\OswisException;

/**
 * Thrown when a public registration submission collides with an existing
 * AppUser (returning participant from a previous year) and we have just
 * sent the user a magic-link login email instead of failing the request.
 *
 * Controllers catch this specifically and render a success-style response
 * (not an error), since for the user the outcome is positive: their
 * identity was recognised and a login link is on its way.
 */
final class ReturningParticipantException extends OswisException
{
}
