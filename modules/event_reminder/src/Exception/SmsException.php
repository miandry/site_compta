<?php

namespace Drupal\event_reminder\Exception;

/**
 * SMS gateway failure.
 */
class SmsException extends \RuntimeException {

  /**
   * @param bool $temporary
   *   TRUE when retrying later can succeed (network error, 429, 5xx).
   */
  public function __construct(string $message, protected bool $temporary = TRUE, ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous);
  }

  public function isTemporary(): bool {
    return $this->temporary;
  }

}
