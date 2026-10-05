<?php

namespace Drupal\event_reminder\Plugin\Mail;

use Drupal\Core\Mail\MailFormatHelper;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\event_reminder\Service\HttpEmailSender;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Event Reminder emails through the provider chosen on the settings page.
 *
 * Selected automatically for the event_reminder module (system.mail
 * interface.event_reminder) when a third-party provider is configured.
 *
 * @Mail(
 *   id = "event_reminder_third_party",
 *   label = @Translation("Event Reminder : fournisseur email tiers"),
 *   description = @Translation("Brevo, SendGrid ou Mailgun via leur API HTTP.")
 * )
 */
class ThirdPartyMail implements MailInterface, ContainerFactoryPluginInterface {

  public function __construct(
    protected HttpEmailSender $sender,
    protected LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('event_reminder.email_sender'),
      $container->get('logger.channel.event_reminder'),
    );
  }

  public function format(array $message) {
    $message['body'] = MailFormatHelper::wrapMail(implode("\n\n", array_map('strval', $message['body'])));
    return $message;
  }

  public function mail(array $message) {
    try {
      $this->sender->send($message['to'], (string) $message['subject'], (string) $message['body']);
      return TRUE;
    }
    catch (\RuntimeException $e) {
      $this->logger->error('Email vers @to non envoyé : @msg', ['@to' => $message['to'], '@msg' => $e->getMessage()]);
      return FALSE;
    }
  }

}
