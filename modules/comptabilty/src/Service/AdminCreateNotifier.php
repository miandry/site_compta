<?php

namespace Drupal\comptabilty\Service;

use Drupal\comptability_access\ApiUser;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Emails the site admin when a non-admin creates an operation / lot.
 */
class AdminCreateNotifier {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected BirdSms $birdSms,
  ) {}

  public function notifyIfNeeded(NodeInterface $node): void {
    $config = $this->configFactory->get('comptabilty.settings');
    $bundles = $config->get('notify_bundles') ?: ['operation', 'operation_lot'];
    if (!in_array($node->bundle(), $bundles, TRUE)) {
      return;
    }
    $author = $node->getOwner();
    if (!$author instanceof UserInterface || ApiUser::isAdminAccount($author)) {
      return;
    }
    if ($config->get('notify_admin_sms')) {
      $this->sendSms($node, $author);
    }
    if ($config->get('notify_admin_on_create')) {
      $this->sendEmail($node, $author);
    }
  }

  /**
   * Bird SMS to the admin phone; never blocks the save.
   */
  protected function sendSms(NodeInterface $node, UserInterface $author): void {
    $config = $this->configFactory->get('comptabilty.settings');
    $to = preg_replace('/[^\d+]/', '', (string) $config->get('admin_notify_phone'));
    $from = trim((string) $config->get('bird_sms_from'));
    if ($to === '' || $from === '' || !$this->birdSms->isConfigured()) {
      $this->loggerFactory->get('comptabilty')->warning('SMS admin non envoyé : numéro, expéditeur ou clé Bird manquant.');
      return;
    }
    $type = $node->bundle() === 'operation_lot' ? 'Saisie multiple' : 'Operation';
    $summary = $this->summary($node);
    $text = sprintf(
      '%s par %s : %s%s',
      $type,
      $author->getAccountName(),
      $node->label(),
      isset($summary['Montant']) ? ' ' . ($summary['Mouvement'] ?? '') . ' ' . $summary['Montant'] : (isset($summary['Total lot']) ? ' ' . $summary['Total lot'] : ''),
    );
    try {
      $this->birdSms->sendText($to, mb_substr($text, 0, 300), $from);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('comptabilty')->error('SMS admin Bird : @msg', ['@msg' => $e->getMessage()]);
    }
  }

  protected function sendEmail(NodeInterface $node, UserInterface $author): void {
    $config = $this->configFactory->get('comptabilty.settings');

    $to = trim((string) ($config->get('admin_notify_email') ?: ''));
    if ($to === '') {
      $to = (string) $this->configFactory->get('system.site')->get('mail');
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
      $this->loggerFactory->get('comptabilty')->warning('Admin notify skipped: no valid admin email configured.');
      return;
    }

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $params = [
      'node' => $node,
      'author' => $author,
      'summary' => $this->summary($node),
      'link' => $this->frontendLink($node),
    ];

    $result = $this->mailManager->mail('comptabilty', 'admin_create', $to, $langcode, $params, NULL, TRUE);
    if (empty($result['result'])) {
      $this->loggerFactory->get('comptabilty')->error('Failed to email admin about node @nid by @user.', [
        '@nid' => $node->id(),
        '@user' => $author->getAccountName(),
      ]);
    }
  }

  protected function summary(NodeInterface $node): array {
    $rows = [
      'Type' => $node->bundle() === 'operation_lot' ? "Lot d'opérations" : 'Opération',
      'Libellé' => $node->label(),
      'Auteur' => $node->getOwner()->getAccountName(),
    ];
    if ($node->hasField('field_amount') && !$node->get('field_amount')->isEmpty()) {
      $rows['Montant'] = number_format((float) $node->get('field_amount')->value, 0, ',', ' ') . ' Ar';
    }
    if ($node->hasField('field_mouvement_argent') && !$node->get('field_mouvement_argent')->isEmpty()) {
      $rows['Mouvement'] = (string) $node->get('field_mouvement_argent')->value;
    }
    if ($node->hasField('field_operation_date') && !$node->get('field_operation_date')->isEmpty()) {
      $raw = (string) $node->get('field_operation_date')->value;
      $rows['Date'] = substr($raw, 0, 10);
    }
    if ($node->hasField('field_lot_total') && !$node->get('field_lot_total')->isEmpty()) {
      $rows['Total lot'] = number_format((float) $node->get('field_lot_total')->value, 0, ',', ' ') . ' Ar';
    }
    return $rows;
  }

  protected function frontendLink(NodeInterface $node): string {
    $base = \Drupal::request() ? \Drupal::request()->getSchemeAndHttpHost() : '';
    if ($node->bundle() === 'operation_lot') {
      return $base . '/#/operations/saisie-multiple?lot=' . $node->id();
    }
    return $base . '/#/operations/' . $node->id();
  }

}
