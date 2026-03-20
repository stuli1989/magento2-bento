<?php
/**
 * Missing Email Exception
 *
 * Thrown when an event is missing the required email field.
 * This is a permanent failure that should not be retried.
 *
 * @category  ArtLounge
 * @package   ArtLounge_BentoCore
 */

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model;

/**
 * Exception for missing email in Bento events
 *
 * This exception indicates a permanent failure - retrying will not help
 * because the data is fundamentally invalid.
 */
class MissingEmailException extends \RuntimeException
{
}
