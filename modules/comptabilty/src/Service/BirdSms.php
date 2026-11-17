<?php

declare(strict_types=1);

namespace Drupal\comptabilty\Service;

use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Bird SMS API (POST /v1/sms/messages) over Drupal's HTTP client.
 *
 * Same calls as the official messagebird/sdk `$bird->sms->send()`, which needs
 * Symfony 7 and cannot be installed next to Drupal 9 (Symfony 4).
 *
 * The key comes from $settings['bird_api_key'] in settings.php only. Its
 * prefix gives the regional host: bk_eu1_… → https://eu1.platform.bird.com.
 */
class BirdSms {

  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
  ) {}

  public static function apiKey(): string {
    $key = Settings::get('bird_api_key', '');
    return is_string($key) ? trim($key) : '';
  }

  public function isConfigured(): bool {
    $key = self::apiKey();
    return $key !== '' && $key !== 'bk_xxxxxxxxx';
  }

  /**
   * Built-in or workspace template, e.g. 'bird_otp_verification' + ['code' => '493021'].
   *
   * @return array{id: string, status: string}
   */
  public function sendTemplate(string $to, string $slug, array $parameters = [], ?string $language = NULL, ?string $from = NULL): array {
    $template = ['slug' => $slug];
    if ($parameters) {
      $template['parameters'] = $parameters;
    }
    if ($language) {
      $template['language'] = $language;
    }
    $payload = ['to' => $to, 'template' => $template];
    // Built-in templates reject `from`; workspace templates require it.
    if ($from) {
      $payload['from'] = $from;
    }
    return $this->post($payload);
  }

  /**
   * Free text. `from` = alphanumeric sender ID (3–11 chars) or an owned number.
   *
   * @return array{id: string, status: string}
   */
  public function sendText(string $to, string $text, string $from, string $category = 'transactional'): array {
    return $this->post([
      'to' => $to,
      'from' => $from,
      'text' => $text,
      'category' => $category,
    ]);
  }

  /**
   * @return array{id: string, status: string}
   */
  protected function post(array $payload): array {
    $key = self::apiKey();
    if (!$this->isConfigured()) {
      throw new \RuntimeException("Bird : clé API manquante (\$settings['bird_api_key'] dans settings.php).");
    }
    $region = preg_match('/^bk_([a-z]{2}\d)_/', $key, $m) ? $m[1] : 'eu1';

    try {
      $response = $this->httpClient->request('POST', "https://{$region}.platform.bird.com/v1/sms/messages", [
        'headers' => [
          'Authorization' => 'Bearer ' . $key,
          'Accept' => 'application/json',
        ],
        'json' => $payload,
        'timeout' => 30,
        'connect_timeout' => 10,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Bird injoignable : ' . str_replace($key, '***', $e->getMessage()), 0, $e);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();
    if ($status !== 202 && $status !== 200 && $status !== 201) {
      throw new \RuntimeException("Bird SMS : HTTP {$status} " . mb_substr(str_replace($key, '***', $body), 0, 300));
    }
    $data = json_decode($body, TRUE) ?: [];
    $this->logger->info('Bird SMS @id accepté pour @to.', [
      '@id' => $data['id'] ?? '?',
      '@to' => substr($payload['to'], 0, 6) . '…' . substr($payload['to'], -2),
    ]);
    return ['id' => (string) ($data['id'] ?? ''), 'status' => (string) ($data['status'] ?? '')];
  }

}
