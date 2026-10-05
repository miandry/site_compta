<?php

namespace Drupal\event_reminder\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\Utility\Token;
use Drupal\event_reminder\Exception\SmsException;
use Drupal\event_reminder\PhoneNumber;
use Drupal\event_reminder\Service\SmsSenderInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends the email and SMS reminder of one event, then flags it as sent.
 *
 * Progress is kept per event so that a retried item (SMS gateway down) never
 * sends the email twice.
 *
 * @QueueWorker(
 *   id = "event_reminder_queue",
 *   title = @Translation("Event reminders"),
 *   cron = {"time" = 60}
 * )
 */
class ReminderQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Temporary SMS failures tolerated before giving up on the SMS.
   */
  public const MAX_ATTEMPTS = 5;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected SmsSenderInterface $smsSender,
    protected Token $token,
    protected LanguageManagerInterface $languageManager,
    protected KeyValueFactoryInterface $keyValue,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('plugin.manager.mail'),
      $container->get('event_reminder.sms_sender'),
      $container->get('token'),
      $container->get('language_manager'),
      $container->get('keyvalue'),
      $container->get('logger.channel.event_reminder'),
    );
  }

  public function processItem($data) {
    $nid = (int) ($data['nid'] ?? 0);
    $node = $nid ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    if (!$node instanceof NodeInterface || $node->bundle() !== 'event' || !$node->isPublished() || $node->get('field_reminder_sent')->value) {
      return;
    }
    $person = $node->get('field_person')->entity;
    if (!$person) {
      $this->logger->warning('Événement @nid sans personne concernée : rappel ignoré.', ['@nid' => $nid]);
      $this->markSent($node);
      return;
    }

    $config = $this->configFactory->get('event_reminder.settings');
    $store = $this->keyValue->get('event_reminder.progress');
    $progress = $store->get($nid, ['email' => FALSE, 'sms' => FALSE, 'attempts' => 0]);
    $context = ['@nid' => $nid, '@title' => $node->label()];

    if ($config->get('send_email') && !$progress['email']) {
      $to = $this->personEmail($person);
      if ($to) {
        $langcode = $this->languageManager->getDefaultLanguage()->getId();
        $result = $this->mailManager->mail('event_reminder', 'reminder', $to, $langcode, ['node' => $node, 'person' => $person]);
        if (!empty($result['result'])) {
          $this->logger->info('Email de rappel envoyé pour « @title » (@nid).', $context);
        }
        else {
          $this->logger->error("Échec de l'email de rappel pour « @title » (@nid).", $context);
        }
      }
      else {
        $this->logger->notice("« @title » (@nid) : la personne n'a pas d'email valide.", $context);
      }
      $progress['email'] = TRUE;
      $store->set($nid, $progress);
    }

    if ($config->get('send_sms') && !$progress['sms']) {
      $phone = PhoneNumber::toE164($this->personPhone($person));
      if ($phone) {
        $message = $this->token->replace((string) $config->get('sms_body'), ['node' => $node], ['clear' => TRUE]);
        try {
          $this->smsSender->send($phone, $message);
          $this->logger->info('SMS de rappel envoyé pour « @title » (@nid).', $context);
        }
        catch (SmsException $e) {
          $progress['attempts']++;
          $retry = $e->isTemporary() && $progress['attempts'] < self::MAX_ATTEMPTS;
          $this->logger->error('SMS de rappel « @title » (@nid), tentative @n : @msg', $context + ['@n' => $progress['attempts'], '@msg' => $e->getMessage()]);
          if ($retry) {
            $store->set($nid, $progress);
            throw new SuspendQueueException($e->getMessage(), 0, $e);
          }
        }
      }
      else {
        $this->logger->notice("« @title » (@nid) : la personne n'a pas de numéro de téléphone valide.", $context);
      }
      $progress['sms'] = TRUE;
    }

    $this->markSent($node);
    $store->delete($nid);
  }

  protected function markSent(NodeInterface $node): void {
    $node->set('field_reminder_sent', TRUE);
    $node->save();
  }

  protected function personEmail($person): ?string {
    foreach (['field_field_email', 'field_email', 'mail'] as $field) {
      if ($person->hasField($field) && ($value = trim((string) $person->get($field)->value)) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return $value;
      }
    }
    return NULL;
  }

  protected function personPhone($person): ?string {
    foreach (['field_phone', 'field_phone_number', 'field_telephone'] as $field) {
      if ($person->hasField($field) && ($value = trim((string) $person->get($field)->value))) {
        return $value;
      }
    }
    return NULL;
  }

}
