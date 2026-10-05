<?php

namespace Drupal\event_reminder\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\event_reminder\Exception\SmsException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * SMS over HTTP.
 *
 * Providers:
 * - http_generic: POST {endpoint} {"to","from","message"}, Bearer {api_key}.
 * - twilio: endpoint = Account SID, api_key = Auth token.
 * - orange_mg: Orange SMS API; endpoint = sender number (+261…) registered
 *   with Orange, api_key = OAuth access token.
 *
 * The API key is read from $settings['event_reminder_sms_api_key'] first, so
 * it never has to be stored in exported configuration.
 */
class HttpSmsSender implements SmsSenderInterface {

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  public static function apiKey(ConfigFactoryInterface $config_factory): string {
    $key = Settings::get('event_reminder_sms_api_key', '');
    if (is_string($key) && trim($key) !== '') {
      return trim($key);
    }
    return trim((string) $config_factory->get('event_reminder.settings')->get('sms_gateway.api_key'));
  }

  public function isConfigured(): bool {
    $gateway = $this->gateway();
    return trim((string) $gateway['endpoint']) !== '' && self::apiKey($this->configFactory) !== '';
  }

  public function send(string $to, string $message): void {
    if (!$this->isConfigured()) {
      throw new SmsException('Passerelle SMS non configurée (URL ou clé API manquante).', FALSE);
    }
    $gateway = $this->gateway();
    $message = mb_substr($message, 0, 160);
    $key = self::apiKey($this->configFactory);
    $endpoint = trim((string) $gateway['endpoint']);
    $sender = trim((string) $gateway['sender']) ?: 'EVENT';

    [$url, $options] = match ($gateway['provider']) {
      'twilio' => [
        'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($endpoint) . '/Messages.json',
        ['auth' => [$endpoint, $key], 'form_params' => ['To' => $to, 'From' => $sender, 'Body' => $message]],
      ],
      'orange_mg' => [
        'https://api.orange.com/smsmessaging/v1/outbound/' . rawurlencode('tel:' . $endpoint) . '/requests',
        [
          'headers' => ['Authorization' => 'Bearer ' . $key],
          'json' => [
            'outboundSMSMessageRequest' => [
              'address' => 'tel:' . $to,
              'senderAddress' => 'tel:' . $endpoint,
              'senderName' => $sender,
              'outboundSMSTextMessage' => ['message' => $message],
            ],
          ],
        ],
      ],
      default => [
        $endpoint,
        [
          'headers' => ['Authorization' => 'Bearer ' . $key],
          'json' => ['to' => $to, 'from' => $sender, 'message' => $message],
        ],
      ],
    };

    try {
      $response = $this->httpClient->request('POST', $url, $options + ['timeout' => 30, 'connect_timeout' => 10, 'http_errors' => FALSE]);
    }
    catch (GuzzleException $e) {
      throw new SmsException('Passerelle SMS injoignable : ' . $this->redact($e->getMessage(), $key), TRUE, $e);
    }

    $status = $response->getStatusCode();
    if (!in_array($status, [200, 201, 202], TRUE)) {
      $body = mb_substr((string) $response->getBody(), 0, 300);
      throw new SmsException("Passerelle SMS : HTTP {$status} " . $this->redact($body, $key), $status === 429 || $status >= 500);
    }
    $this->logger->info('SMS envoyé à @to.', ['@to' => $this->mask($to)]);
  }

  /**
   * @return array{provider: string, endpoint: string, sender: string}
   */
  protected function gateway(): array {
    $gateway = (array) $this->configFactory->get('event_reminder.settings')->get('sms_gateway');
    return [
      'provider' => (string) ($gateway['provider'] ?? 'http_generic'),
      'endpoint' => (string) ($gateway['endpoint'] ?? ''),
      'sender' => (string) ($gateway['sender'] ?? 'EVENT'),
    ];
  }

  protected function redact(string $text, string $key): string {
    return $key === '' ? $text : str_replace($key, '***', $text);
  }

  protected function mask(string $phone): string {
    return substr($phone, 0, 6) . str_repeat('•', max(0, strlen($phone) - 8)) . substr($phone, -2);
  }

}
