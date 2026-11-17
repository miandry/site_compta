<?php

/**
 * @file
 * Bird SMS test — equivalent of the messagebird/sdk example.
 *
 * Usage (from the Drupal root):
 *   vendor/bin/drush scr sites/default/modules/comptabilty/scripts/bird_sms_send.php -- +261321234567
 *
 * Needs $settings['bird_api_key'] in sites/default/settings.php.
 */

declare(strict_types=1);

$to = $extra[0] ?? '+261321234567';

/** @var \Drupal\comptabilty\Service\BirdSms $bird */
$bird = \Drupal::service('comptabilty.bird_sms');

$message = $bird->sendTemplate(
  to: $to,
  slug: 'bird_otp_verification',
  parameters: ['code' => '493021'],
);

echo $message['id'], ' ', $message['status'], "\n";
