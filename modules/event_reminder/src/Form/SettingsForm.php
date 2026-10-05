<?php

namespace Drupal\event_reminder\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\event_reminder\Service\HttpEmailSender;
use Drupal\event_reminder\Service\HttpSmsSender;

/**
 * /admin/config/system/event-reminder.
 */
class SettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['event_reminder.settings'];
  }

  public function getFormId() {
    return 'event_reminder_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('event_reminder.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Rappels activés'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['days_before'] = [
      '#type' => 'number',
      '#title' => $this->t("Jours avant l'événement"),
      '#min' => 0,
      '#max' => 30,
      '#default_value' => $config->get('days_before'),
      '#description' => $this->t('1 = la veille. Le cron doit tourner au moins une fois par jour (idéalement toutes les heures).'),
    ];
    $form['timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Fuseau horaire de référence'),
      '#options' => array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()),
      '#default_value' => $config->get('timezone'),
    ];
    $form['send_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Envoyer un email'),
      '#default_value' => $config->get('send_email'),
    ];
    $form['send_sms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Envoyer un SMS'),
      '#default_value' => $config->get('send_sms'),
    ];

    $email = $form_state->getValue('email_transport') ?? (array) $config->get('email_transport');
    $form['email_transport'] = [
      '#type' => 'details',
      '#title' => $this->t('Email — fournisseur tiers'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#states' => ['visible' => [':input[name="send_email"]' => ['checked' => TRUE]]],
    ];
    $form['email_transport']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Fournisseur'),
      '#options' => HttpEmailSender::PROVIDERS,
      '#default_value' => $email['provider'] ?? 'drupal',
      '#description' => $this->t("« Drupal » utilise le mail PHP du serveur (souvent bloqué ou classé spam en local / mutualisé). Un fournisseur tiers envoie via son API HTTPS."),
    ];
    $third_party = ['visible' => [':input[name="email_transport[provider]"]' => ['!value' => 'drupal']]];
    $mailgun = ['visible' => [':input[name="email_transport[provider]"]' => ['value' => 'mailgun']]];
    $form['email_transport']['from_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Adresse expéditeur'),
      '#default_value' => $email['from_email'] ?? '',
      '#description' => $this->t('Doit être une adresse ou un domaine vérifié chez le fournisseur.'),
      '#states' => $third_party,
    ];
    $form['email_transport']['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Nom de l'expéditeur"),
      '#default_value' => $email['from_name'] ?? '',
      '#states' => $third_party,
    ];
    $form['email_transport']['domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Domaine Mailgun'),
      '#default_value' => $email['domain'] ?? '',
      '#placeholder' => 'mg.exemple.mg',
      '#states' => $mailgun,
    ];
    $form['email_transport']['region'] = [
      '#type' => 'radios',
      '#title' => $this->t('Région Mailgun'),
      '#options' => ['us' => 'US (api.mailgun.net)', 'eu' => 'EU (api.eu.mailgun.net)'],
      '#default_value' => $email['region'] ?? 'us',
      '#states' => $mailgun,
    ];
    $email_key = Settings::get('event_reminder_email_api_key');
    $email_key_in_settings = is_string($email_key) && trim($email_key) !== '';
    $form['email_transport']['api_key_status'] = [
      '#type' => 'item',
      '#title' => $this->t("Clé d'API email"),
      '#markup' => HttpEmailSender::apiKey($this->configFactory()) !== ''
        ? ($email_key_in_settings ? $this->t('Configurée dans settings.php.') : $this->t('Configurée (dans la configuration : déplacez-la vers settings.php).'))
        : $this->t('Manquante.'),
      '#states' => $third_party,
    ];
    $form['email_transport']['examples'] = [
      '#type' => 'details',
      '#title' => $this->t('Exemples de configuration'),
      '#states' => $third_party,
      'content' => [
        '#type' => 'inline_template',
        '#template' => '<p>{{ intro }}</p>
<p><strong>Bird</strong> — app.bird.com › Developers › API keys (commence par <code>bk_eu1_</code> ou <code>bk_us1_</code> : la région est déduite de la clé) ; l\'expéditeur doit appartenir à un domaine vérifié.</p>
<pre>$settings[\'event_reminder_email_api_key\'] = \'bk_eu1_…\';</pre>
<p><strong>Brevo</strong> (gratuit : 300 emails / jour) — app.brevo.com › SMTP &amp; API › Clés API (commence par <code>xkeysib-</code>) ; vérifiez l\'expéditeur dans Expéditeurs &amp; IP.</p>
<pre>$settings[\'event_reminder_email_api_key\'] = \'xkeysib-…\';</pre>
<p><strong>SendGrid</strong> — app.sendgrid.com › Settings › API Keys, droit « Mail Send » (commence par <code>SG.</code>) ; vérifiez l\'expéditeur dans Sender Authentication.</p>
<pre>$settings[\'event_reminder_email_api_key\'] = \'SG.…\';</pre>
<p><strong>Mailgun</strong> — app.mailgun.com › Sending › Domains (domaine, ex. <code>mg.exemple.mg</code>) puis API Security › Sending API key ; région EU si le compte est en Europe.</p>
<pre>$settings[\'event_reminder_email_api_key\'] = \'…\';</pre>',
        '#context' => ['intro' => $this->t('Ajoutez la clé dans sites/default/settings.php (jamais exportée avec la configuration), puis videz le cache :')],
      ],
    ];
    $form['email_transport']['test_to'] = [
      '#type' => 'email',
      '#title' => $this->t('Envoyer un email de test à'),
      '#default_value' => $this->currentUser()->getEmail(),
    ];
    $form['email_transport']['send_test'] = [
      '#type' => 'submit',
      '#value' => $this->t("Enregistrer et envoyer l'email de test"),
      '#submit' => ['::submitForm', '::sendTest'],
    ];

    $form['sms_gateway'] = [
      '#type' => 'details',
      '#title' => $this->t('Passerelle SMS'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#states' => ['visible' => [':input[name="send_sms"]' => ['checked' => TRUE]]],
    ];
    $form['sms_gateway']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Fournisseur'),
      '#options' => [
        'http_generic' => $this->t('HTTP générique (POST JSON to / from / message)'),
        'twilio' => 'Twilio',
        'orange_mg' => 'Orange Madagascar (Orange SMS API)',
      ],
      '#default_value' => $config->get('sms_gateway.provider'),
    ];
    $form['sms_gateway']['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL / identifiant'),
      '#default_value' => $config->get('sms_gateway.endpoint'),
      '#description' => $this->t('HTTP générique : URL de la passerelle. Twilio : Account SID. Orange : numéro expéditeur (+261…).'),
    ];
    $form['sms_gateway']['sender'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Expéditeur'),
      '#maxlength' => 11,
      '#default_value' => $config->get('sms_gateway.sender'),
    ];
    $from_settings = is_string(Settings::get('event_reminder_sms_api_key')) && trim(Settings::get('event_reminder_sms_api_key')) !== '';
    $form['sms_gateway']['api_key_status'] = [
      '#type' => 'item',
      '#title' => $this->t("Clé d'API"),
      '#markup' => HttpSmsSender::apiKey($this->configFactory()) !== ''
        ? ($from_settings ? $this->t('Configurée dans settings.php.') : $this->t('Configurée (dans la configuration : déplacez-la vers settings.php).'))
        : $this->t('Manquante.'),
      '#description' => $this->t("Pour ne jamais l'exporter avec la configuration, ajoutez dans settings.php : <code>\$settings['event_reminder_sms_api_key'] = '…';</code>"),
    ];

    $form['messages'] = [
      '#type' => 'details',
      '#title' => $this->t('Messages'),
      '#open' => TRUE,
    ];
    $form['messages']['email_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Objet de l'email"),
      '#default_value' => $config->get('email_subject'),
    ];
    $form['messages']['email_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t("Corps de l'email"),
      '#rows' => 6,
      '#default_value' => $config->get('email_body'),
    ];
    $form['messages']['sms_body'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Texte du SMS'),
      '#maxlength' => 160,
      '#default_value' => $config->get('sms_body'),
      '#description' => $this->t('160 caractères maximum après remplacement des jetons.'),
    ];
    $form['messages']['tokens'] = [
      '#theme' => 'token_tree_link',
      '#token_types' => ['node'],
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $email = $form_state->getValue('email_transport');
    if ($email['provider'] !== 'drupal' && trim($email['from_email']) === '') {
      $form_state->setErrorByName('email_transport][from_email', $this->t("L'adresse expéditeur est obligatoire avec un fournisseur tiers."));
    }
    if ($email['provider'] === 'mailgun' && trim($email['domain']) === '') {
      $form_state->setErrorByName('email_transport][domain', $this->t('Le domaine Mailgun est obligatoire.'));
    }
    parent::validateForm($form, $form_state);
  }

  public function sendTest(array &$form, FormStateInterface $form_state) {
    $to = trim((string) $form_state->getValue(['email_transport', 'test_to']));
    if ($to === '') {
      $this->messenger()->addError($this->t("Indiquez l'adresse qui doit recevoir l'email de test."));
      return;
    }
    $provider = HttpEmailSender::PROVIDERS[$form_state->getValue(['email_transport', 'provider'])] ?? 'Drupal';
    $result = \Drupal::service('plugin.manager.mail')->mail('event_reminder', 'test', $to, $this->currentUser()->getPreferredLangcode(), ['provider' => $provider]);
    if (!empty($result['result'])) {
      $this->messenger()->addStatus($this->t('Email de test envoyé à @to via @provider.', ['@to' => $to, '@provider' => $provider]));
    }
    else {
      $this->messenger()->addError($this->t("Échec de l'email de test via @provider : voir le journal (admin/reports/dblog, type event_reminder).", ['@provider' => $provider]));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $email = $form_state->getValue('email_transport');
    $gateway = $form_state->getValue('sms_gateway');
    $this->config('event_reminder.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('days_before', (int) $form_state->getValue('days_before'))
      ->set('timezone', $form_state->getValue('timezone'))
      ->set('send_email', (bool) $form_state->getValue('send_email'))
      ->set('send_sms', (bool) $form_state->getValue('send_sms'))
      ->set('email_transport.provider', $email['provider'])
      ->set('email_transport.from_email', trim($email['from_email']))
      ->set('email_transport.from_name', trim($email['from_name']))
      ->set('email_transport.domain', trim($email['domain']))
      ->set('email_transport.region', $email['region'])
      ->set('sms_gateway.provider', $gateway['provider'])
      ->set('sms_gateway.endpoint', trim($gateway['endpoint']))
      ->set('sms_gateway.sender', trim($gateway['sender']))
      ->set('email_subject', $form_state->getValue('email_subject'))
      ->set('email_body', $form_state->getValue('email_body'))
      ->set('sms_body', $form_state->getValue('sms_body'))
      ->save();
    event_reminder_route_mail($email['provider']);
    parent::submitForm($form, $form_state);
  }

}
