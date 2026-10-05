<?php

namespace Drupal\event_reminder\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Sends emails through a third-party HTTP API (Brevo, SendGrid, Mailgun).
 *
 * The API key is read from $settings['event_reminder_email_api_key'] first, so
 * it never has to be stored in exported configuration.
 */
class HttpEmailSender {

  public const PROVIDERS = [
    'drupal' => 'Drupal (mail PHP du serveur)',
    'bird' => 'Bird',
    'brevo' => 'Brevo (ex-Sendinblue)',
    'sendgrid' => 'SendGrid',
    'mailgun' => 'Mailgun',
  ];

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  public static function apiKey(ConfigFactoryInterface $config_factory): string {
    $key = Settings::get('event_reminder_email_api_key', '');
    if (is_string($key) && trim($key) !== '') {
      return trim($key);
    }
    return trim((string) $config_factory->get('event_reminder.settings')->get('email_transport.api_key'));
  }

  public function provider(): string {
    $provider = (string) $this->configFactory->get('event_reminder.settings')->get('email_transport.provider');
    return isset(self::PROVIDERS[$provider]) ? $provider : 'drupal';
  }

  /**
   * Whether the configured third-party provider can send.
   */
  public function isConfigured(): bool {
    $transport = $this->transport();
    if ($this->provider() === 'drupal' || $transport['from_email'] === '' || self::apiKey($this->configFactory) === '') {
      return FALSE;
    }
    return $this->provider() !== 'mailgun' || $transport['domain'] !== '';
  }

  /**
   * @throws \RuntimeException
   *   With a French, key-free message when the provider refuses the email.
   */
  public function send(string $to, string $subject, string $body): void {
    $provider = $this->provider();
    if (!$this->isConfigured()) {
      throw new \RuntimeException('Fournisseur email non configuré (expéditeur, clé API ou domaine manquant).');
    }
    $t = $this->transport();
    $key = self::apiKey($this->configFactory);
    $from = ['email' => $t['from_email'], 'name' => $t['from_name']];

    $from_header = $t['from_name'] !== '' ? "{$t['from_name']} <{$t['from_email']}>" : $t['from_email'];

    [$url, $options, $ok] = match ($provider) {
      // The key prefix gives the region host: bk_eu1_… → eu1.platform.bird.com.
      'bird' => [
        'https://' . (preg_match('/^bk_([a-z]{2}\d)_/', $key, $m) ? $m[1] : 'eu1') . '.platform.bird.com/v1/email/messages',
        [
          'headers' => ['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json'],
          'json' => [
            'from' => $from_header,
            'to' => [$to],
            'subject' => $subject,
            'text' => $body,
            'html' => '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES), FALSE) . '</p>',
            'category' => 'transactional',
          ],
        ],
        [200, 201, 202],
      ],
      'brevo' => [
        'https://api.brevo.com/v3/smtp/email',
        [
          'headers' => ['api-key' => $key, 'Accept' => 'application/json'],
          'json' => ['sender' => $from, 'to' => [['email' => $to]], 'subject' => $subject, 'textContent' => $body],
        ],
        [201],
      ],
      'sendgrid' => [
        'https://api.sendgrid.com/v3/mail/send',
        [
          'headers' => ['Authorization' => 'Bearer ' . $key],
          'json' => [
            'personalizations' => [['to' => [['email' => $to]]]],
            'from' => $from,
            'subject' => $subject,
            'content' => [['type' => 'text/plain', 'value' => $body]],
          ],
        ],
        [202],
      ],
      'mailgun' => [
        'https://' . ($t['region'] === 'eu' ? 'api.eu.mailgun.net' : 'api.mailgun.net') . '/v3/' . rawurlencode($t['domain']) . '/messages',
        [
          'auth' => ['api', $key],
          'form_params' => [
            'from' => $from_header,
            'to' => $to,
            'subject' => $subject,
            'text' => $body,
          ],
        ],
        [200],
      ],
    };

    try {
      $response = $this->httpClient->request('POST', $url, $options + ['timeout' => 30, 'connect_timeout' => 10, 'http_errors' => FALSE]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException(self::PROVIDERS[$provider] . ' injoignable : ' . str_replace($key, '***', $e->getMessage()), 0, $e);
    }
    $status = $response->getStatusCode();
    if (!in_array($status, $ok, TRUE)) {
      $detail = str_replace($key, '***', mb_substr((string) $response->getBody(), 0, 300));
      throw new \RuntimeException(self::PROVIDERS[$provider] . " a refusé l'email (HTTP {$status}) : {$detail}");
    }
    $this->logger->info('Email envoyé via @provider.', ['@provider' => self::PROVIDERS[$provider]]);
  }

  /**
   * @return array{from_email: string, from_name: string, domain: string, region: string}
   */
  protected function transport(): array {
    $t = (array) $this->configFactory->get('event_reminder.settings')->get('email_transport');
    return [
      'from_email' => trim((string) ($t['from_email'] ?? '')),
      'from_name' => trim((string) ($t['from_name'] ?? '')),
      'domain' => trim((string) ($t['domain'] ?? '')),
      'region' => (string) ($t['region'] ?? 'us'),
    ];
  }

}
