<?php

namespace Drupal\comptabilty\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin email when a non-admin creates an operation.
 */
class NotifySettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['comptabilty.settings'];
  }

  public function getFormId() {
    return 'comptabilty_notify_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('comptabilty.settings');
    $site_mail = $this->config('system.site')->get('mail');

    $form['notify_admin_on_create'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Envoyer un email à l’admin quand un non-admin crée une opération ou une saisie multiple'),
      '#default_value' => $config->get('notify_admin_on_create'),
    ];

    $form['admin_notify_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Adresse email de l’admin'),
      '#description' => $this->t('Laisser vide pour utiliser l’email du site (@mail).', ['@mail' => $site_mail ?: '—']),
      '#default_value' => $config->get('admin_notify_email'),
    ];

    $bird_ready = \Drupal::service('comptabilty.bird_sms')->isConfigured();
    $form['sms'] = [
      '#type' => 'details',
      '#title' => $this->t('SMS via Bird'),
      '#open' => TRUE,
      '#description' => $bird_ready
        ? $this->t('Clé Bird détectée dans settings.php.')
        : $this->t("Clé Bird absente : ajoutez <code>\$settings['bird_api_key'] = 'bk_…';</code> dans settings.php."),
    ];
    $form['sms']['notify_admin_sms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Envoyer un SMS à l’admin quand un non-admin crée une opération ou une saisie multiple'),
      '#default_value' => $config->get('notify_admin_sms'),
    ];
    $form['sms']['admin_notify_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Téléphone de l’admin'),
      '#description' => $this->t('Format international, ex. +261341234567.'),
      '#default_value' => $config->get('admin_notify_phone'),
    ];
    $form['sms']['bird_sms_from'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Expéditeur (sender ID)'),
      '#description' => $this->t('3 à 11 caractères avec au moins une lettre (ex. Compta), ou un numéro de votre espace Bird.'),
      '#maxlength' => 16,
      '#default_value' => $config->get('bird_sms_from'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('notify_admin_sms')) {
      $phone = preg_replace('/[^\d+]/', '', (string) $form_state->getValue('admin_notify_phone'));
      if (!preg_match('/^\+\d{8,15}$/', $phone)) {
        $form_state->setErrorByName('admin_notify_phone', $this->t('Numéro au format +261…'));
      }
      if (trim((string) $form_state->getValue('bird_sms_from')) === '') {
        $form_state->setErrorByName('bird_sms_from', $this->t('Expéditeur requis pour un SMS libre.'));
      }
    }
    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('comptabilty.settings')
      ->set('notify_admin_on_create', (bool) $form_state->getValue('notify_admin_on_create'))
      ->set('admin_notify_email', trim((string) $form_state->getValue('admin_notify_email')))
      ->set('notify_admin_sms', (bool) $form_state->getValue('notify_admin_sms'))
      ->set('admin_notify_phone', preg_replace('/[^\d+]/', '', (string) $form_state->getValue('admin_notify_phone')))
      ->set('bird_sms_from', trim((string) $form_state->getValue('bird_sms_from')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
