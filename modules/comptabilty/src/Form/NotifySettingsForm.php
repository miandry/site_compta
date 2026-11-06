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

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('comptabilty.settings')
      ->set('notify_admin_on_create', (bool) $form_state->getValue('notify_admin_on_create'))
      ->set('admin_notify_email', trim((string) $form_state->getValue('admin_notify_email')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
