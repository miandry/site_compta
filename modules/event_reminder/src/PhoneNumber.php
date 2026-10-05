<?php

namespace Drupal\event_reminder;

/**
 * E.164 normalization for Malagasy numbers (+261…).
 */
final class PhoneNumber {

  /**
   * "034 12 345 67", "0341234567", "261341234567", "+261 34 12 345 67"
   * → "+261341234567". Other international numbers starting with + or 00
   * are kept. Returns NULL when the number cannot be used.
   */
  public static function toE164(?string $phone, string $country_code = '261'): ?string {
    $phone = trim((string) $phone);
    if ($phone === '') {
      return NULL;
    }
    $international = str_starts_with($phone, '+') || str_starts_with($phone, '00');
    $digits = preg_replace('/\D+/', '', $phone);
    if ($international) {
      $digits = preg_replace('/^00/', '', $digits);
    }
    elseif (str_starts_with($digits, '0')) {
      $digits = $country_code . substr($digits, 1);
    }
    elseif (strlen($digits) === 9) {
      $digits = $country_code . $digits;
    }
    return preg_match('/^[1-9]\d{7,14}$/', $digits) ? '+' . $digits : NULL;
  }

}
