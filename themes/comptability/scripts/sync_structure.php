<?php

/**
 * @file
 * Aligns the printblue content model (operation / person) with the
 * comptability theme frontend.
 *
 * Run with: vendor/bin/drush php:script themes/comptability/scripts/sync_structure.php
 *
 * Idempotent: safe to run several times.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;

$display_repository = \Drupal::service('entity_display.repository');

if (!\Drupal::moduleHandler()->moduleExists('telephone')) {
  \Drupal::service('module_installer')->install(['telephone']);
  echo "  module enabled: telephone\n";
}

/**
 * Creates a field (storage + instance + form/view widgets) when missing.
 */
$ensure_field = function (string $bundle, string $field_name, string $type, string $label, bool $required, string $widget, string $formatter) use ($display_repository) {
  if (FieldConfig::loadByName('node', $bundle, $field_name)) {
    return;
  }
  if (!FieldStorageConfig::loadByName('node', $field_name)) {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => 1,
    ])->save();
  }
  FieldConfig::create([
    'field_name' => $field_name,
    'entity_type' => 'node',
    'bundle' => $bundle,
    'label' => $label,
    'required' => $required,
  ])->save();
  $display_repository->getFormDisplay('node', $bundle)->setComponent($field_name, ['type' => $widget])->save();
  $display_repository->getViewDisplay('node', $bundle)->setComponent($field_name, ['type' => $formatter])->save();
  echo "  field added: $bundle.$field_name ($type)\n";
};

$ensure_field('person', 'field_phone_number', 'telephone', 'Numéro de téléphone', FALSE, 'telephone_default', 'telephone_link');
$ensure_field('operation', 'field_label', 'string_long', 'Libellé', TRUE, 'string_textarea', 'basic_string');

$references = [
  'operation' => [
    'field_person' => ['node', 'person'],
    'field_operation_type' => ['taxonomy_term', 'operation_type'],
    'field_category' => ['taxonomy_term', 'category'],
    'field_method_payment' => ['taxonomy_term', 'method_payment'],
    'field_caisse' => ['taxonomy_term', 'caisse'],
  ],
];

foreach ($references as $bundle => $fields) {
  foreach ($fields as $field_name => [$target_type, $target_bundle]) {
    $field = FieldConfig::loadByName('node', $bundle, $field_name);
    if (!$field) {
      echo "  ! missing field $bundle.$field_name\n";
      continue;
    }
    $handler_settings = $field->getSetting('handler_settings') ?: [];
    if (($handler_settings['target_bundles'] ?? NULL) === [$target_bundle => $target_bundle]) {
      continue;
    }
    $handler_settings['target_bundles'] = [$target_bundle => $target_bundle];
    $field->setSetting('handler', 'default:' . $target_type);
    $field->setSetting('handler_settings', $handler_settings);
    $field->save();
    echo "  target bundle set: $bundle.$field_name -> $target_bundle\n";
  }
}

// Justificatif envoyé depuis l'API sans texte alternatif.
$proof = FieldConfig::loadByName('node', 'operation', 'field_image_prof');
if ($proof && $proof->getSetting('alt_field_required')) {
  $proof->setSetting('alt_field_required', FALSE)->save();
  echo "  alt optional: operation.field_image_prof\n";
}

$terms = [
  'operation_type' => ['Versement espèces', 'Virement reçu', 'Virement émis', 'Retrait', 'Chèque', 'Frais bancaires'],
  'category' => ['Écolage', 'Vente', 'Salaire', 'Fournisseur', 'Autres'],
  'method_payment' => ['Espèces', 'Virement bancaire', 'Chèque', 'MVola', 'Orange Money', 'Airtel Money'],
  'caisse' => ['Caisse principale', 'Caisse secondaire', 'Banque BOA', 'Compte MVola'],
];

foreach ($terms as $vid => $names) {
  $count = \Drupal::entityQuery('taxonomy_term')
    ->accessCheck(FALSE)
    ->condition('vid', $vid)
    ->count()
    ->execute();
  if ($count > 0) {
    continue;
  }
  foreach ($names as $weight => $name) {
    Term::create(['vid' => $vid, 'name' => $name, 'weight' => $weight])->save();
  }
  echo "  seeded $vid: " . count($names) . " terms\n";
}

echo "Structure OK\n";
