<?php

namespace Drupal\event_reminder\Service;

/**
 * Sends a text message through the configured gateway.
 */
interface SmsSenderInterface {

  /**
   * @param string $to
   *   Recipient in E.164 format (+261XXXXXXXXX).
   * @param string $message
   *   Text, truncated to 160 characters.
   *
   * @throws \Drupal\event_reminder\Exception\SmsException
   *   When the gateway is not configured, unreachable or refuses the message.
   */
  public function send(string $to, string $message): void;

  /**
   * Whether a gateway endpoint and API key are available.
   */
  public function isConfigured(): bool;

}
