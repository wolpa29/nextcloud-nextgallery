<?php
declare(strict_types=1);

namespace OCA\Nextgallery\Exception;

use OCP\AppFramework\OCS\OCSException;

/**
 * HTTP 409 Conflict exception for Nextcloud OCS responses.
 * Nextcloud core (NC34/35) does not provide OCSConflictException.
 */
class FaceConflictException extends OCSException {
    public function __construct(string $message = 'Conflict', int $code = 409, ?\Throwable $previous = null) {
        parent::__construct($message, 409, $previous);
    }
}
